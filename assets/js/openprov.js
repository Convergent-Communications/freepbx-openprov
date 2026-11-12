/* OpenProv admin UI. Vanilla JS over the same action API as fwconsole/GraphQL
 * (ajax.php?module=openprov&command=api&action=<name>, JSON body). */
(function () {
	'use strict';
	const app = document.getElementById('openprov-app');
	if (!app) return;
	const main = document.getElementById('op-main');
	const flash = document.getElementById('op-flash');

	// ---- helpers -------------------------------------------------------------
	const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const $ = (sel, root = main) => root.querySelector(sel);
	const $$ = (sel, root = main) => Array.from(root.querySelectorAll(sel));
	const ago = (ts) => {
		if (!ts) return 'never';
		const s = Math.floor(Date.now() / 1000) - ts;
		if (s < 60) return s + 's ago';
		if (s < 3600) return Math.floor(s / 60) + 'm ago';
		if (s < 86400) return Math.floor(s / 3600) + 'h ago';
		return new Date(ts * 1000).toLocaleString();
	};

	async function api(action, args = {}, form = null) {
		const url = 'ajax.php?module=openprov&command=api&action=' + encodeURIComponent(action);
		const opts = { method: 'POST', credentials: 'same-origin' };
		if (form) {
			opts.body = form;
		} else {
			opts.body = JSON.stringify(args);
			opts.headers = { 'Content-Type': 'application/json' };
		}
		const res = await fetch(url, opts);
		let body;
		try {
			body = await res.json();
		} catch (e) {
			throw new Error('Unexpected response (' + res.status + ')');
		}
		if (!body.status) throw new Error(body.message || 'Request failed');
		return body.data;
	}

	function say(msg, kind = 'success') {
		flash.innerHTML = `<div class="alert alert-${kind} alert-dismissible">${esc(msg)}<button type="button" class="close" data-dismiss="alert">&times;</button></div>`;
		if (kind === 'success') setTimeout(() => { flash.innerHTML = ''; }, 5000);
	}
	const fail = (e) => say(e.message || String(e), 'danger');

	async function guard(btn, fn) {
		if (btn) btn.disabled = true;
		try {
			return await fn();
		} catch (e) {
			fail(e);
		} finally {
			if (btn) btn.disabled = false;
		}
	}

	// Shared lookups, loaded once.
	const cache = {};
	async function lookups() {
		if (!cache.models) {
			const [models, schema, keytypes, targets, extensions, templates, assets, vpn] = await Promise.all([
				api('models'), api('schema'), api('keytypes'), api('targets'), api('extensions'), api('templates.list'), api('assets.list'), api('vpn.clients'),
			]);
			Object.assign(cache, { models, schema, keytypes, targets, extensions, templates, assets, vpn });
		}
		return cache;
	}
	const refreshLookups = () => { delete cache.models; return lookups(); };

	// ---- tabs ----------------------------------------------------------------
	const tabs = { devices, templates, assets, discover, events, settings };
	function selectTab(name) {
		$$('[data-tab]', app).forEach((n) => n.classList.toggle('active', n.dataset.tab === name));
	}
	app.addEventListener('click', (e) => {
		const a = e.target.closest('[data-tab]');
		if (!a) return;
		e.preventDefault();
		selectTab(a.dataset.tab);
		flash.innerHTML = '';
		tabs[a.dataset.tab]().catch(fail);
	});

	// ---- Phones ----------------------------------------------------------------
	async function devices() {
		const [list] = await Promise.all([api('devices.list'), lookups()]);
		const rows = list.map((d) => {
			const exts = d.lines.map((l) => esc(l.extension)).join(', ');
			const SYNC = { current: ['success', 'up to date'], pending: ['info', 'update sent'], stale: ['warning', 'needs update'], never: ['warning', 'never fetched'], error: ['danger', 'config error'] };
			const [cls, txt] = SYNC[d.sync] || ['secondary', d.sync || ''];
			const state = !d.enabled ? '<span class="badge badge-secondary">disabled</span>' : `<span class="badge badge-${cls}">${txt}</span>`;
			return `<tr>
				<td><a href="#" data-edit-device="${d.id}">${esc(d.name || d.mac_pretty)}</a></td>
				<td><code>${esc(d.mac_pretty)}</code></td><td>${esc(d.model)}</td><td>${esc(d.template || '—')}</td>
				<td>${exts || '—'}</td><td>${esc(d.last_fw || '')}</td><td>${ago(d.last_seen)} ${esc(d.last_ip)}</td><td>${state}</td>
				<td class="text-nowrap"><button class="btn btn-sm btn-default" data-resync="${d.id}">Update phone</button></td></tr>`;
		}).join('');
		main.innerHTML = `
			<div class="op-toolbar">
				<button class="btn btn-primary" id="op-add-device">Add phone</button>
				<button class="btn btn-default" id="op-resync-all">Update all phones</button>
				<button class="btn btn-default" id="op-export">Export CSV</button>
				<label class="btn btn-default mb-0">Import CSV <input type="file" id="op-import" accept=".csv,text/csv" hidden></label>
			</div>
			<table class="table table-striped table-sm op-table">
				<thead><tr><th>Name</th><th>MAC</th><th>Model</th><th>Template</th><th>Extensions</th><th>Firmware</th><th>Last fetch</th><th></th><th></th></tr></thead>
				<tbody>${rows || '<tr><td colspan="9" class="text-muted">No phones yet. Add one, or adopt one from the Discover tab.</td></tr>'}</tbody>
			</table>
			<p class="text-muted small">Phones fetch from <code>${esc(cache.info?.bootstrap_url || '')}</code>. Saving a change updates affected phones automatically.</p>`;
		$('#op-add-device').onclick = () => deviceEditor(null);
		$('#op-resync-all').onclick = (e) => guard(e.target, async () => report(await api('resync', { all: true })));
		$('#op-export').onclick = async () => {
			const csv = await api('export.csv');
			const a = document.createElement('a');
			a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
			a.download = 'openprov-phones.csv';
			a.click();
		};
		$('#op-import').onchange = (e) => guard(null, async () => {
			const csv = await e.target.files[0].text();
			const r = await api('import.csv', { csv });
			say(`Imported ${r.imported.length} phone(s)` + (r.errors.length ? '; errors: ' + r.errors.map((x) => `row ${x.row}: ${x.error}`).join('; ') : ''), r.errors.length ? 'warning' : 'success');
			devices();
		});
		$$('[data-edit-device]').forEach((a) => (a.onclick = (e) => { e.preventDefault(); deviceEditor(+a.dataset.editDevice); }));
		$$('[data-resync]').forEach((b) => (b.onclick = () => guard(b, async () => report(await api('resync', { devices: [+b.dataset.resync] })))));
	}

	function report(results) {
		const ok = results.filter((r) => r.ok).length;
		const msgs = results.filter((r) => !r.ok).map((r) => `${r.mac}: ${r.message}`);
		say(`${ok} of ${results.length} phone(s) told to update` + (msgs.length ? ' — ' + msgs.join('; ') : ''), msgs.length ? 'warning' : 'success');
	}

	// ---- shared editors ------------------------------------------------------
	function settingsForm(values, inherited, isDevice) {
		const groups = {};
		cache.schema.forEach((s) => (groups[s.group] = groups[s.group] || []).push(s));
		const field = (s) => {
			const v = values[s.id] ?? '';
			const inh = inherited ? inherited[s.id] : s.default;
			const ph = inh !== undefined && inh !== '' ? `inherit: ${inh}` : 'not set';
			const name = `data-setting="${esc(s.id)}"`;
			let input;
			const opt = (val, label, sel) => `<option value="${esc(val)}" ${sel ? 'selected' : ''}>${esc(label)}</option>`;
			if (s.type === 'select' || s.type === 'bool') {
				const options = s.type === 'bool' ? [{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }] : s.options;
				input = `<select class="form-control form-control-sm" ${name}>${opt('', ph, v === '')}${options.map((o) => opt(o.value, o.label, String(v) === String(o.value))).join('')}</select>`;
			} else if (s.type.startsWith('asset:')) {
				const kind = s.type.slice(6);
				input = `<select class="form-control form-control-sm" ${name}>${opt('', ph, v === '')}${cache.assets.filter((a) => a.kind === kind).map((a) => opt(a.id, a.name + (a.version ? ' (' + a.version + ')' : ''), String(v) === String(a.id))).join('')}</select>`;
			} else if (s.type === 'vpn') {
				input = `<select class="form-control form-control-sm" ${name}>${opt('', ph, v === '')}${opt('off', 'Off', v === 'off')}${opt('auto', "Auto (user's System Admin client)", v === 'auto')}${cache.vpn.map((c) => opt(c.id, `Client ${c.id}: ${c.description}`, String(v) === String(c.id))).join('')}</select>`;
			} else if (s.type === 'textarea') {
				input = `<textarea class="form-control form-control-sm" rows="2" ${name} placeholder="${esc(ph)}">${esc(v)}</textarea>`;
			} else if (s.type === 'timezone') {
				input = `<input class="form-control form-control-sm" ${name} value="${esc(v)}" placeholder="${esc(inh ? 'inherit: ' + inh : 'PBX time zone')}" list="op-tz">`;
			} else {
				input = `<input class="form-control form-control-sm" type="${s.type === 'password' ? 'password' : 'text'}" ${name} value="${esc(v)}" placeholder="${esc(ph)}" autocomplete="off">`;
			}
			const overridden = isDevice && v !== '';
			return `<div class="form-group col-md-4 ${overridden ? 'op-overridden' : ''}"><label class="small mb-0" title="${esc(s.help || '')}">${esc(s.label)}${s.help ? ' <i class="fa fa-question-circle text-muted"></i>' : ''}${overridden ? ' <span class="badge badge-info">this phone</span>' : ''}</label>${input}</div>`;
		};
		const tz = ['America/New_York', 'America/Chicago', 'America/Denver', 'America/Phoenix', 'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu', 'America/Toronto', 'America/Halifax', 'Europe/London', 'UTC'];
		return `<datalist id="op-tz">${tz.map((z) => `<option value="${z}">`).join('')}</datalist>` +
			Object.entries(groups).map(([g, list]) => `<fieldset class="op-group"><legend>${esc(g)}</legend><div class="form-row">${list.map(field).join('')}</div></fieldset>`).join('') +
			(isDevice ? '<p class="small text-muted">Blank fields use the template value shown in grey. Fields marked <span class="badge badge-info">this phone</span> override it.</p>' : '<p class="small text-muted">Blank fields inherit from the parent template, then the built-in defaults.</p>');
	}
	const readSettings = () => {
		const out = {};
		$$('[data-setting]').forEach((el) => { if (el.value !== '') out[el.dataset.setting] = el.value; });
		return out;
	};

	function keyTypeOptions(selected, isDevice) {
		const opts = [];
		opts.push(`<option value="" ${selected === '' ? 'selected' : ''}>${isDevice ? 'Use template' : '(unused)'}</option>`);
		if (isDevice) opts.push(`<option value="none" ${selected === 'none' ? 'selected' : ''}>Blank (hide template key)</option>`);
		Object.entries(cache.keytypes).forEach(([t, d]) => {
			if (t === 'none') return;
			opts.push(`<option value="${esc(t)}" ${selected === t ? 'selected' : ''}>${esc(d.label)}</option>`);
		});
		return opts.join('');
	}

	/** Key grid. `own` = keys stored on this object, `effective` = what the phone gets (device only). */
	/**
	 * Key grid. `own` = keys stored on this object. On a phone, `tpl` = what its
	 * template chain puts on each key (resolved labels), shown beside the
	 * phone's own override.
	 */
	function keysEditor(count, own, tpl, isDevice) {
		return keysToolbar(count, isDevice) + bankTable(count, own, tpl, isDevice, 'line');
	}

	const describeKey = (k) => {
		if (!k || k.type === 'none') return '—';
		const type = (cache.keytypes[k.type]?.label || k.type).split(' (')[0];
		if (k.type === 'line') return `Line ${k.account || 1}${k.label ? ' (' + k.label + ')' : ''}`;
		return `${type}: ${k.label || k.value}${k.label && k.value && k.label !== k.value ? ' · ' + k.value : ''}`;
	};

	function bankTable(count, own, tpl, isDevice, bank) {
		const byPos = {};
		own.filter((k) => k.bank === bank).forEach((k) => (byPos[k.position] = k));
		const fromTpl = (tpl && tpl[bank]) || {};
		let rows = '';
		for (let p = 1; p <= count; p++) {
			const k = byPos[p] || { type: '', value: '', label: '', account: 1 };
			const t = fromTpl[p];
			const hidden = p > 12 && !byPos[p] && !t;
			const cls = [hidden ? 'op-more' : '', isDevice && byPos[p] ? 'op-override' : ''].join(' ');
			rows += `<tr data-pos="${p}" class="${cls}">
				<td class="text-muted">${p}</td>
				${isDevice ? `<td class="op-tplcol">${esc(describeKey(t))}</td>` : ''}
				<td><select class="form-control form-control-sm" data-k="type">${keyTypeOptions(k.type, isDevice)}</select></td>
				<td><input class="form-control form-control-sm" data-k="value" value="${esc(k.value)}" list="op-targets"></td>
				<td><input class="form-control form-control-sm" data-k="label" value="${esc(k.label)}" placeholder="auto from name"></td>
				<td><input class="form-control form-control-sm" data-k="account" type="number" min="1" max="16" value="${esc(k.account || 1)}" style="width:5em"></td></tr>`;
		}
		const head = isDevice
			? '<th>#</th><th>From the template</th><th>This phone</th><th>Value</th><th>Label</th><th>Line</th>'
			: '<th>#</th><th>Type</th><th>Value</th><th>Label</th><th>Line</th>';
		return `<table class="table table-sm table-striped table-hover op-keys" data-bank="${bank}"><thead><tr>${head}</tr></thead><tbody>${rows}</tbody></table>`;
	}

	function keysToolbar(count, isDevice) {
		const targets = cache.targets.map((t) => `<option value="${esc(t.value)}">${esc(t.label)} [${esc(t.category)}${t.hint ? ', BLF' : ''}]</option>`).join('');
		return `<datalist id="op-targets">${targets}</datalist>
			<div class="op-toolbar">
				<button class="btn btn-sm btn-default" data-toggle-more>Show all ${count} keys</button>
				<span class="ml-3">Fill BLF keys with
					<select class="form-control form-control-sm d-inline-block" style="width:auto" id="op-fill-exts" multiple size="1">${cache.extensions.map((x) => `<option value="${esc(x.extension)}">${esc(x.extension + ' ' + x.name)}</option>`).join('')}</select>
					starting at key <input type="number" min="1" max="${count}" value="2" id="op-fill-start" class="form-control form-control-sm d-inline-block" style="width:5em">
					<button class="btn btn-sm btn-default" id="op-fill">Fill</button>
					<button class="btn btn-sm btn-default" id="op-fill-all">All extensions</button>
				</span>
			</div>
			<p class="small text-muted">${isDevice
				? 'Keys follow the template unless you pick something for this phone (highlighted rows). '
				: ''}Fill writes into the grid below (highlighted); nothing is saved until you click Save. Leave a label blank to use the person's current name (renames update phones automatically). <code>{ext}</code> means the phone's own extension (e.g. <code>*21{ext}</code> follow-me toggle).</p>`;
	}

	/** Fill consecutive BLF keys in the grid (line bank), overwriting what is there. Not saved until Save. */
	function fillGrid(exts, start, exclude = []) {
		const list = exts.filter((x) => !exclude.includes(x));
		const rows = $$('.op-keys[data-bank="line"] tbody tr');
		let pos = start;
		const filled = [];
		for (const ext of list) {
			const tr = rows.find((r) => +r.dataset.pos === pos);
			if (!tr) break;
			tr.querySelector('[data-k="type"]').value = 'blf';
			tr.querySelector('[data-k="value"]').value = ext;
			tr.querySelector('[data-k="label"]').value = '';
			tr.classList.add('op-changed', 'op-show');
			filled.push(pos);
			pos++;
		}
		if (!filled.length) return say('Nothing to fill (no keys left after that position, or no extensions selected).', 'warning');
		const skipped = list.length - filled.length;
		say(`Filled keys ${filled[0]}–${filled[filled.length - 1]} with ${filled.length} extension(s)${skipped ? ` (${skipped} didn't fit)` : ''}. Not saved yet — click Save to apply.`, 'info');
		rows.find((r) => +r.dataset.pos === filled[0])?.scrollIntoView({ block: 'center', behavior: 'smooth' });
	}

	function wireKeys(exclude = []) {
		const more = $('[data-toggle-more]');
		if (more) more.onclick = (e) => { e.preventDefault(); $$('.op-keys tr.op-more').forEach((r) => r.classList.toggle('op-show')); };
		const start = () => +$('#op-fill-start').value || 1;
		$('#op-fill').onclick = (e) => {
			e.preventDefault();
			const exts = Array.from($('#op-fill-exts').selectedOptions).map((o) => o.value);
			if (!exts.length) return say('Pick one or more extensions to fill first.', 'warning');
			fillGrid(exts, start(), exclude);
		};
		$('#op-fill-all').onclick = (e) => { e.preventDefault(); fillGrid(cache.extensions.map((x) => x.extension), start(), exclude); };
		$$('.op-keys select, .op-keys input').forEach((el) => el.addEventListener('change', () => el.closest('tr').classList.add('op-changed')));
		// Picking a known target fills the type for you.
		$$('.op-keys [data-k="value"]').forEach((inp) => (inp.onchange = () => {
			const t = cache.targets.find((x) => x.value === inp.value);
			const typeSel = inp.closest('tr').querySelector('[data-k="type"]');
			if (t && (!typeSel.value || typeSel.value === 'none')) typeSel.value = t.type;
		}));
	}

	const readKeys = () => $$('.op-keys tbody tr').map((tr) => ({
		bank: tr.closest('table').dataset.bank,
		position: +tr.dataset.pos,
		type: tr.querySelector('[data-k="type"]').value,
		value: tr.querySelector('[data-k="value"]').value.trim(),
		label: tr.querySelector('[data-k="label"]').value.trim(),
		account: +tr.querySelector('[data-k="account"]').value || 1,
	})).filter((k) => k.type !== '');

	const rawText = (raw) => Object.entries(raw || {}).map(([k, v]) => `${k} = ${v}`).join('\n');

	// ---- device editor -------------------------------------------------------
	async function deviceEditor(id) {
		await lookups();
		const d = id ? await api('device.get', { device: id }) : {
			mac: '', model: 'T87W', name: '', template_id: null, enabled: true, settings: {}, raw: {}, lines: [{ account: 1, extension: '' }], keys: [], line_keys: 84,
		};
		const model = cache.models.find((m) => m.id === d.model) || cache.models[0];
		const lineRows = (d.lines.length ? d.lines : [{ account: 1, extension: '' }]).map((l) => lineRow(l)).join('');
		const tv = d.template_view || { name: null, chain: [], settings: {}, keys: {} };
		const nKeys = d.keys.filter((k) => k.type !== '').length;
		const nSettings = Object.keys(d.settings || {}).filter((k) => k !== 'expansion_modules').length;
		const nRaw = Object.keys(d.raw || {}).length;
		main.innerHTML = `
			<div class="op-toolbar"><a href="#" id="op-back">&larr; Phones</a></div>
			<h3>${id ? esc(d.name || d.mac_pretty) : 'Add phone'}</h3>
			${id ? `<div class="alert ${tv.name ? 'alert-secondary' : 'alert-warning'} op-tplbox">
				${tv.name
					? `Uses template <b>${tv.chain.map((t) => esc(t.name)).join(' → ')}</b> <a href="#" id="op-goto-tpl" class="ml-2">Edit template &rarr;</a>`
					: 'No template: everything below comes from the built-in defaults and this phone.'}
				<div class="small mt-1">Overrides on this phone: <b>${nKeys}</b> key(s) · <b>${nSettings}</b> setting(s) · <b>${nRaw}</b> raw key(s). Everything else follows the template.</div>
			</div>` : ''}
			${d.render_error ? `<div class="alert alert-danger">${esc(d.render_error)}</div>` : ''}
			${id && d.in_sync === false ? '<div class="alert alert-info">This phone has not fetched its latest configuration yet.</div>' : ''}
			<div class="form-row">
				<div class="form-group col-md-3"><label>MAC address</label><input class="form-control" id="op-mac" value="${esc(d.mac_pretty || d.mac)}" placeholder="c4:fc:22:1d:d6:37"></div>
				<div class="form-group col-md-2"><label>Model</label><select class="form-control" id="op-model">${cache.models.map((m) => `<option value="${esc(m.id)}" ${m.id === d.model ? 'selected' : ''}>${esc(m.name)}${m.verified ? '' : ' *'}</option>`).join('')}</select></div>
				<div class="form-group col-md-3"><label>Name</label><input class="form-control" id="op-name" value="${esc(d.name)}" placeholder="defaults to the first line"></div>
				<div class="form-group col-md-3"><label>Template</label><select class="form-control" id="op-template"><option value="">(none)</option>${cache.templates.map((t) => `<option value="${t.id}" ${t.id === d.template_id ? 'selected' : ''}>${esc(t.name)}</option>`).join('')}</select></div>
				<div class="form-group col-md-1"><label>Enabled</label><select class="form-control" id="op-enabled"><option value="1">Yes</option><option value="0" ${d.enabled ? '' : 'selected'}>No</option></select></div>
			</div>
			<h4>Lines</h4>
			<table class="table table-sm table-striped" id="op-lines"><thead><tr><th>Account</th><th>Extension</th><th>Label</th><th></th></tr></thead><tbody>${lineRows}</tbody></table>
			<button class="btn btn-sm btn-default" id="op-add-line">Add line</button>
			<ul class="nav nav-tabs mt-3" id="op-subtabs">
				<li class="nav-item"><a class="nav-link active" href="#" data-sub="keys">Keys</a></li>
				<li class="nav-item"><a class="nav-link" href="#" data-sub="settings">Settings</a></li>
				<li class="nav-item"><a class="nav-link" href="#" data-sub="raw">Raw keys</a></li>
				${id ? '<li class="nav-item"><a class="nav-link" href="#" data-sub="config">Config preview</a></li><li class="nav-item"><a class="nav-link" href="#" data-sub="activity">Activity</a></li>' : ''}
			</ul>
			<div class="op-sub" data-pane="keys">${keysEditor(model.lineKeys || 0, d.keys, tv.keys, true)}
				${model.expansion ? `<div class="form-inline mb-2"><label class="mr-2">Expansion modules attached</label><select class="form-control form-control-sm" data-setting="expansion_modules">${[0, 1, 2, 3, 4, 5, 6].slice(0, model.expansion.modules + 1).map((n) => `<option value="${n}" ${String(d.settings.expansion_modules || 0) === String(n) ? 'selected' : ''}>${n}</option>`).join('')}</select></div>` : ''}
				${model.expansion ? Array.from({ length: model.expansion.modules }, (_, i) => `<details class="mb-2" ${d.keys.some((k) => k.bank === 'exp' + (i + 1)) ? 'open' : ''}><summary>Expansion module ${i + 1} (${model.expansion.keysPerModule} keys)</summary>${bankTable(model.expansion.keysPerModule, d.keys, tv.keys, true, 'exp' + (i + 1))}</details>`).join('') : ''}</div>
			<div class="op-sub d-none" data-pane="settings">${settingsForm(d.settings, tv.settings, true)}</div>
			<div class="op-sub d-none" data-pane="raw"><p class="small text-muted">One <code>key = value</code> per line, written last (after templates). Use for anything the settings don't cover. <code>%NULL%</code> clears a value on the phone.</p><textarea class="form-control op-mono" rows="12" id="op-raw">${esc(rawText(d.raw))}</textarea></div>
			<div class="op-sub d-none" data-pane="config"><pre class="op-pre" id="op-config">Loading…</pre></div>
			<div class="op-sub d-none" data-pane="activity"><div id="op-dev-events">Loading…</div></div>
			<div class="op-toolbar op-actions">
				<button class="btn btn-primary" id="op-save">Save</button>
				${id ? `<button class="btn btn-default" id="op-push">Update phone now</button>
				<button class="btn btn-default" id="op-reboot">Reboot phone</button>
				<button class="btn btn-default" id="op-rearm" title="Allow the shared bootstrap credential again (after a factory reset)">Re-arm (factory reset)</button>
				<button class="btn btn-danger float-right" id="op-delete">Delete</button>` : ''}
			</div>
			${id ? `<p class="small text-muted">Provisioning user <code>${esc(d.prov_user)}</code> · firmware ${esc(d.last_fw || 'unknown')} · last fetch ${ago(d.last_seen)} from ${esc(d.last_ip || '—')}${d.claimable ? ' · <b>accepts the bootstrap credential</b>' : ''}</p>` : ''}`;

		$('#op-back').onclick = (e) => { e.preventDefault(); devices(); };
		if ($('#op-goto-tpl')) $('#op-goto-tpl').onclick = (e) => { e.preventDefault(); selectTab('templates'); templateEditor(d.template_id); };
		$('#op-add-line').onclick = () => {
			const n = $$('#op-lines tbody tr').length + 1;
			$('#op-lines tbody').insertAdjacentHTML('beforeend', lineRow({ account: n, extension: '' }));
			wireLines();
		};
		wireLines();
		$$('#op-subtabs [data-sub]').forEach((a) => (a.onclick = async (e) => {
			e.preventDefault();
			$$('#op-subtabs .nav-link').forEach((x) => x.classList.toggle('active', x === a));
			$$('.op-sub').forEach((p) => p.classList.toggle('d-none', p.dataset.pane !== a.dataset.sub));
			if (a.dataset.sub === 'config') $('#op-config').textContent = (await api('render', { device: id })).text;
			if (a.dataset.sub === 'activity') $('#op-dev-events').innerHTML = eventsTable(await api('events', { mac: d.mac, limit: 100 }));
		}));
		wireKeys(d.lines.map((l) => l.extension));
		$('#op-save').onclick = (e) => guard(e.target, async () => {
			const lines = $$('#op-lines tbody tr').map((tr) => {
				const ext = tr.querySelector('[data-l="extension"]').value;
				const line = { account: +tr.querySelector('[data-l="account"]').value, extension: ext, label: tr.querySelector('[data-l="label"]').value };
				if (ext === '__custom__') {
					line.extension = '';
					line.custom = {};
					tr.querySelectorAll('[data-c]').forEach((i) => { if (i.value !== '') line.custom[i.dataset.c] = i.value; });
				}
				return line;
			}).filter((l) => l.extension !== '' || l.custom);
			const saved = await api('device.save', {
				id: id || undefined, mac: $('#op-mac').value, model: $('#op-model').value, name: $('#op-name').value,
				template: $('#op-template').value || null, enabled: $('#op-enabled').value === '1',
				lines, settings: readSettings(), raw: $('#op-raw').value, keys: readKeys(),
			});
			say('Saved. Phones affected by the change were told to update.');
			deviceEditor(saved.id);
		});
		if (id) {
			$('#op-push').onclick = (e) => guard(e.target, async () => report(await api('resync', { devices: [id] })));
			$('#op-reboot').onclick = (e) => confirm('Reboot this phone now?') && guard(e.target, async () => report(await api('resync', { devices: [id], reboot: true })));
			$('#op-rearm').onclick = (e) => confirm('Issue new credentials and accept the bootstrap credential until the phone fetches again?') && guard(e.target, async () => { await api('device.rearm', { device: id }); say('Re-armed'); deviceEditor(id); });
			$('#op-delete').onclick = (e) => confirm('Delete this phone from OpenProv?') && guard(e.target, async () => { await api('device.delete', { device: id }); say('Deleted'); devices(); });
		}
	}

	function lineRow(l) {
		const c = (l.custom && !Array.isArray(l.custom)) ? l.custom : null;
		return `<tr><td><input class="form-control form-control-sm" type="number" min="1" max="16" data-l="account" value="${esc(l.account)}" style="width:5em"></td>
			<td><select class="form-control form-control-sm" data-l="extension"><option value="">—</option><option value="__custom__" ${c ? 'selected' : ''}>Other server (custom account)…</option>${cache.extensions.map((x) => `<option value="${esc(x.extension)}" ${!c && x.extension === l.extension ? 'selected' : ''}>${esc(x.extension + ' ' + x.name)}</option>`).join('')}</select>
				<div class="form-row mt-1 op-custom ${c ? '' : 'd-none'}">
					${['server', 'port', 'user', 'auth', 'secret', 'display_name'].map((f) => `<div class="col"><input class="form-control form-control-sm" data-c="${f}" placeholder="${f.replace('_', ' ')}" value="${esc(c?.[f] || '')}" ${f === 'secret' ? 'type="password"' : ''}></div>`).join('')}
				</div></td>
			<td><input class="form-control form-control-sm" data-l="label" value="${esc(l.label || '')}" placeholder="auto"></td>
			<td><button class="btn btn-sm btn-link" data-remove-line>remove</button></td></tr>`;
	}
	function wireLines() {
		$$('[data-remove-line]').forEach((b) => (b.onclick = (e) => { e.preventDefault(); b.closest('tr').remove(); }));
		$$('[data-l="extension"]').forEach((sel) => (sel.onchange = () => sel.closest('td').querySelector('.op-custom').classList.toggle('d-none', sel.value !== '__custom__')));
	}

	// ---- templates -----------------------------------------------------------
	async function templates() {
		await refreshLookups();
		const byId = Object.fromEntries(cache.templates.map((t) => [t.id, t]));
		main.innerHTML = `
			<div class="op-toolbar"><button class="btn btn-primary" id="op-add-tpl">Add template</button></div>
			<table class="table table-striped table-sm op-table"><thead><tr><th>Name</th><th>Inherits from</th><th>Description</th><th>Phones</th></tr></thead>
			<tbody>${cache.templates.map((t) => `<tr><td><a href="#" data-edit-tpl="${t.id}">${esc(t.name)}</a></td><td>${esc(t.parent_id ? byId[t.parent_id]?.name : '—')}</td><td>${esc(t.description)}</td><td>${t.devices}</td></tr>`).join('')}</tbody></table>
			<p class="text-muted small">Set phones up here: keys, settings and files live on templates. A template can inherit from another (built-in defaults &rarr; parent &rarr; template), and each phone only stores the few things that differ from its template.</p>`;
		$('#op-add-tpl').onclick = () => templateEditor(null);
		$$('[data-edit-tpl]').forEach((a) => (a.onclick = (e) => { e.preventDefault(); templateEditor(+a.dataset.editTpl); }));
	}

	async function templateEditor(id) {
		await lookups();
		const t = id ? await api('template.get', { template: id }) : { name: '', parent_id: null, description: '', settings: {}, raw: {}, keys: [] };
		const maxKeys = Math.max(...cache.models.map((m) => m.lineKeys || 0));
		main.innerHTML = `
			<div class="op-toolbar"><a href="#" id="op-back">&larr; Templates</a></div>
			<h3>${id ? esc(t.name) : 'Add template'}</h3>
			<div class="form-row">
				<div class="form-group col-md-3"><label>Name</label><input class="form-control" id="op-tname" value="${esc(t.name)}"></div>
				<div class="form-group col-md-3"><label>Inherits from</label><select class="form-control" id="op-tparent"><option value="">(defaults only)</option>${cache.templates.filter((x) => x.id !== id).map((x) => `<option value="${x.id}" ${x.id === t.parent_id ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select></div>
				<div class="form-group col-md-6"><label>Description</label><input class="form-control" id="op-tdesc" value="${esc(t.description)}"></div>
			</div>
			<ul class="nav nav-tabs" id="op-subtabs">
				<li class="nav-item"><a class="nav-link active" href="#" data-sub="settings">Settings</a></li>
				<li class="nav-item"><a class="nav-link" href="#" data-sub="keys">Keys</a></li>
				<li class="nav-item"><a class="nav-link" href="#" data-sub="raw">Raw keys</a></li>
			</ul>
			<div class="op-sub" data-pane="settings">${settingsForm(t.settings, null, false)}</div>
			<div class="op-sub d-none" data-pane="keys"><p class="small text-muted">Keys beyond a model's key count are ignored for that model (T87W: 84).</p>${keysEditor(maxKeys, t.keys, null, false)}</div>
			<div class="op-sub d-none" data-pane="raw"><textarea class="form-control op-mono" rows="14" id="op-raw">${esc(rawText(t.raw))}</textarea></div>
			<div class="op-toolbar op-actions">
				<button class="btn btn-primary" id="op-save">Save</button>
				${id ? '<button class="btn btn-default" id="op-copy">Copy…</button><button class="btn btn-danger float-right" id="op-delete">Delete</button>' : ''}
			</div>`;
		$('#op-back').onclick = (e) => { e.preventDefault(); templates(); };
		$$('#op-subtabs [data-sub]').forEach((a) => (a.onclick = (e) => {
			e.preventDefault();
			$$('#op-subtabs .nav-link').forEach((x) => x.classList.toggle('active', x === a));
			$$('.op-sub').forEach((p) => p.classList.toggle('d-none', p.dataset.pane !== a.dataset.sub));
		}));
		wireKeys();
		$('#op-save').onclick = (e) => guard(e.target, async () => {
			const saved = await api('template.save', {
				id: id || undefined, name: $('#op-tname').value, parent: $('#op-tparent').value || null,
				description: $('#op-tdesc').value, settings: readSettings(), raw: $('#op-raw').value, keys: readKeys(),
			});
			await refreshLookups();
			say('Saved. Phones using this template were told to update.');
			templateEditor(saved.id);
		});
		if (id) {
			$('#op-copy').onclick = (e) => { const n = prompt('Name for the copy'); if (n) guard(e.target, async () => { const c = await api('template.copy', { template: id, name: n }); await refreshLookups(); templateEditor(c.id); }); };
			$('#op-delete').onclick = (e) => confirm('Delete this template? Phones using it keep their own settings.') && guard(e.target, async () => { await api('template.delete', { template: id }); await refreshLookups(); templates(); });
		}
	}

	// ---- files ---------------------------------------------------------------
	async function assets() {
		const list = await api('assets.list');
		const size = (b) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.ceil(b / 1024) + ' KB');
		main.innerHTML = `
			<form class="form-inline op-toolbar" id="op-upload">
				<select class="form-control mr-2" name="kind"><option value="wallpaper">Wallpaper</option><option value="ringtone">Ringtone (WAV)</option><option value="firmware">Firmware (.rom)</option></select>
				<input type="file" class="form-control mr-2" name="file" required>
				<input class="form-control mr-2" name="model" placeholder="model (firmware, optional)">
				<button class="btn btn-primary">Upload</button>
			</form>
			<p class="small text-muted">Wallpapers are cropped and resized for each model's screen automatically. Assign files in a template or phone (Settings → Display / Audio / Provisioning).</p>
			<table class="table table-striped table-sm op-table"><thead><tr><th>Kind</th><th>Name</th><th>Version</th><th>Model</th><th>Size</th><th>SHA-256</th><th></th></tr></thead>
			<tbody>${list.map((a) => `<tr><td>${esc(a.kind)}</td><td>${esc(a.name)}</td><td>${esc(a.version)}</td><td>${esc(a.model)}</td><td>${size(a.size)}</td><td><code class="small">${esc(a.sha256.slice(0, 16))}…</code></td><td><button class="btn btn-sm btn-link text-danger" data-del-asset="${a.id}">delete</button></td></tr>`).join('') || '<tr><td colspan="7" class="text-muted">No files yet.</td></tr>'}</tbody></table>`;
		$('#op-upload').onsubmit = (e) => {
			e.preventDefault();
			const fd = new FormData(e.target);
			fd.append('name', e.target.file.files[0]?.name || 'file');
			guard(e.target.querySelector('button'), async () => { await api('asset.add', {}, fd); delete cache.models; say('Uploaded'); assets(); });
		};
		$$('[data-del-asset]').forEach((b) => (b.onclick = () => confirm('Delete this file?') && guard(b, async () => { await api('asset.delete', { id: +b.dataset.delAsset }); delete cache.models; assets(); })));
	}

	// ---- discover ------------------------------------------------------------
	async function discover() {
		await lookups();
		const list = await api('discovered', { days: 14 });
		main.innerHTML = `
			<p class="text-muted">Phones that asked this server for a configuration but are not set up yet, and Yealink phones registered to the PBX that OpenProv does not manage.
			Point new phones at <code>${esc((await api('info')).bootstrap_url)}</code> with the bootstrap credentials from Settings (DHCP option 66 or Yealink RPS).</p>
			<table class="table table-striped table-sm op-table"><thead><tr><th>MAC</th><th>Model</th><th>IP</th><th>Seen</th><th>Source</th><th>Extension</th><th>Template</th><th></th></tr></thead>
			<tbody>${list.map((p, i) => `<tr data-i="${i}"><td><code>${esc(p.mac_pretty || '?')}</code></td><td>${esc(p.model || '?')}</td><td>${esc(p.ip)}</td><td>${ago(p.last_seen)}</td><td>${esc(p.source)}</td>
				<td><select class="form-control form-control-sm" data-adopt-ext><option value="">—</option>${cache.extensions.map((x) => `<option value="${esc(x.extension)}" ${x.extension === p.extension ? 'selected' : ''}>${esc(x.extension + ' ' + x.name)}</option>`).join('')}</select></td>
				<td><select class="form-control form-control-sm" data-adopt-tpl>${cache.templates.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('')}</select></td>
				<td>${p.mac && p.model ? '<button class="btn btn-sm btn-primary" data-adopt>Adopt</button>' : '<span class="small text-muted">MAC unknown</span>'}</td></tr>`).join('') || '<tr><td colspan="8" class="text-muted">Nothing discovered in the last 14 days.</td></tr>'}</tbody></table>`;
		$$('[data-adopt]').forEach((b) => (b.onclick = () => {
			const tr = b.closest('tr');
			const p = list[+tr.dataset.i];
			guard(b, async () => {
				const d = await api('adopt', { mac: p.mac, model: p.model, extension: tr.querySelector('[data-adopt-ext]').value || null, template: tr.querySelector('[data-adopt-tpl]').value });
				say('Adopted. The phone gets its configuration at its next request.');
				deviceEditor(d.id);
			});
		}));
	}

	// ---- activity ------------------------------------------------------------
	function eventsTable(list) {
		return `<table class="table table-sm table-striped op-table"><thead><tr><th>Time</th><th>MAC</th><th>IP</th><th>Request</th><th>Status</th><th>Detail</th></tr></thead><tbody>${list.map((e) =>
			`<tr class="${e.status >= 400 ? 'table-warning' : ''}"><td class="text-nowrap">${new Date(e.ts * 1000).toLocaleString()}</td><td><code>${esc(e.mac)}</code></td><td>${esc(e.ip)}</td><td>${esc(e.path)}</td><td>${e.status}</td><td>${esc(e.detail)}</td></tr>`).join('') || '<tr><td colspan="6" class="text-muted">No activity.</td></tr>'}</tbody></table>`;
	}
	async function events() {
		main.innerHTML = `<div class="op-toolbar"><input class="form-control d-inline-block" style="width:16em" id="op-ev-mac" placeholder="filter by MAC"> <button class="btn btn-default" id="op-ev-go">Refresh</button></div><div id="op-ev"></div>`;
		const load = async () => { const mac = $('#op-ev-mac').value.trim(); $('#op-ev').innerHTML = eventsTable(await api('events', mac ? { mac, limit: 300 } : { limit: 300 })); };
		$('#op-ev-go').onclick = () => load().catch(fail);
		await load();
	}

	// ---- settings ------------------------------------------------------------
	const SETTING_HELP = {
		internal_host: 'Address phones on the LAN use (blank = auto-detected).',
		external_host: 'Public hostname/IP for remote phones (template provisioning network "external").',
		phone_scheme: 'https (recommended) or http (lab only; requires an HTTP port).',
		https_port: 'Provisioning HTTPS port. Re-run the web install after changing ports or certificates.',
		http_port: '0 disables plain HTTP.',
		tls_cert: 'Certificate file (certman keeps them in /etc/asterisk/keys).',
		tls_key: '', tls_chain: 'Optional intermediate chain.',
		bootstrap_user: 'Shared first-contact credential (DHCP option 66 / RPS). Only works for phones that are added and not yet claimed.',
		bootstrap_pass: '',
		allowed_networks: 'CIDRs allowed to provision (blank = any). E.g. 192.168.0.0/16, 10.8.0.0/24',
		enforce_ua_mac: 'Refuse a MAC config when the User-Agent MAC differs.',
		auto_adopt: 'Create unknown phones automatically on first bootstrap contact.',
		auto_adopt_template: 'Template id for auto-adopted phones.',
		admin_password: 'Phone web admin password (not "admin").', user_password: 'Phone web user password.',
		auto_resync: 'Update phones right away when you save a change in OpenProv.',
		resync_on_apply_config: 'Also update phones after FreePBX Apply Config when their configuration changed (e.g. a renamed user). Turn off to only update phones by hand or at their next periodic check.',
		event_retention_days: 'Days of activity to keep.',
		vpn_external_remote: '"host port" added after the LAN address in VPN profiles, for off-site phones.',
		default_template_id: 'Template for new phones.',
		vpn_update_method: 'Phones with the VPN on. auto / notify = check-sync (works for every model now that tunnel-bound phones provision through the tunnel); reboot = reboot VPN phones through their web API; none = changes apply at the next reboot.',
		vpn_provisioning_host: 'Address VPN phones on tunnel-bound models (T87W on 185.87.x) provision from once their tunnel is up. Blank = the VPN server\'s tunnel address (e.g. 10.8.0.1). Never the VPN endpoint address: those phones can\'t reach it while connected.',
		firewall_zones: 'FreePBX firewall zones the provisioning port is opened in (custom service "OpenProv provisioning"). VPN phones arrive in the zone of tun0 (Internal by default). Comma-separated: trusted, internal, other, external.',
		reboot_only_when_idle: 'Never reboot a phone that is on a call.',
		reboot_window: 'Only reboot VPN phones inside this window, e.g. 01:00-05:00 (blank = any time).',
	};
	async function settings() {
		const [s, info] = await Promise.all([api('settings.get', { reveal: true }), api('info')]);
		cache.info = info;
		const rows = Object.entries(s).filter(([k]) => k !== 'internal_host_effective').map(([k, v]) => {
			const input = typeof v === 'boolean'
				? `<select class="form-control form-control-sm" data-gs="${k}"><option value="1" ${v ? 'selected' : ''}>Yes</option><option value="0" ${v ? '' : 'selected'}>No</option></select>`
				: `<input class="form-control form-control-sm" data-gs="${k}" value="${esc(v)}" ${k === 'internal_host' ? `placeholder="${esc(s.internal_host_effective)}"` : ''}>`;
			return `<div class="form-group col-md-4"><label class="small mb-0"><code>${esc(k)}</code></label>${input}<div class="small text-muted">${esc(SETTING_HELP[k] || '')}</div></div>`;
		}).join('');
		const w = info.web;
		main.innerHTML = `
			<h4>Provisioning server</h4>
			<p>Bootstrap URL: <code>${esc(info.bootstrap_url)}</code> · user <code>${esc(s.bootstrap_user)}</code> · password <code>${esc(s.bootstrap_pass)}</code></p>
			<p class="small">Web server: ${w.installed ? (w.current ? '<span class="text-success">installed and current</span>' : '<span class="text-warning">out of date</span>') : '<span class="text-danger">not installed</span>'} (HTTPS ${w.https_port}${w.http_port ? ', HTTP ' + w.http_port : ''}).
			${w.current ? '' : `Run <code>${esc(w.command)}</code> as root.`} System Admin VPN server: ${info.vpn_server ? 'enabled' : 'off'}.</p>
			<div class="form-row">${rows}</div>
			<button class="btn btn-primary" id="op-gs-save">Save settings</button>
			<hr><h4>Phone models</h4>
			<p class="small text-muted">Brands: Yealink. Each model is a JSON file in <code>models/yealink/</code> (see docs/ADDING-MODELS.md); other brands need a brand driver (<code>src/Brand</code>). Unverified models use vendor data and haven't been checked on hardware.</p>
			<table class="table table-sm table-striped op-table"><thead><tr><th>Brand</th><th>Model</th><th>Line keys</th><th>Accounts</th><th>Expansion</th><th>Wallpaper</th><th>Verified</th><th>Notes</th></tr></thead>
			<tbody>${(await lookups()).models.map((m) => `<tr><td>${esc(m.brand)}</td><td>${esc(m.id)}</td><td>${m.lineKeys}</td><td>${m.accounts}</td><td>${m.expansion ? `${m.expansion.modules} × ${m.expansion.keysPerModule}` : '—'}</td><td>${m.wallpaper ? `${m.wallpaper.width}×${m.wallpaper.height}` : '—'}</td><td>${m.verified ? '<span class="badge badge-success">yes</span>' : '<span class="text-muted">no</span>'}</td><td class="small">${m.quirks?.vpnBlocksProvisioning ? '<span class="badge badge-warning">VPN: reboot to update</span> ' : ''}${m.verified ? esc(m.notes || '') : ''}</td></tr>`).join('')}</tbody></table>
			<hr><h4>Import from Endpoint Manager</h4>
			<p class="small text-muted">Copies EPM extension mappings (MAC, extension, VPN client) into OpenProv. EPM model names can be mapped, e.g. <code>{"SIP-T57W":"T87W"}</code> for phones that were set up as a stand-in model.</p>
			<div class="form-row"><div class="col-md-4"><input class="form-control" id="op-epm-map" placeholder='{"SIP-T57W":"T87W"}'></div><div class="col-md-3"><select class="form-control" id="op-epm-tpl">${(await lookups()).templates.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('')}</select></div>
			<div class="col-md-5"><button class="btn btn-default" id="op-epm-dry">Preview</button> <button class="btn btn-default" id="op-epm-run">Import</button></div></div>
			<pre class="op-pre mt-2" id="op-epm-out"></pre>`;
		$('#op-gs-save').onclick = (e) => guard(e.target, async () => {
			const vals = {};
			$$('[data-gs]').forEach((el) => (vals[el.dataset.gs] = el.value));
			await api('settings.set', vals);
			say('Settings saved');
			settings();
		});
		const epm = (dryRun) => guard(null, async () => {
			let modelMap = {};
			if ($('#op-epm-map').value.trim()) modelMap = JSON.parse($('#op-epm-map').value);
			const r = await api('import.epm', { dryRun, modelMap, template: $('#op-epm-tpl').value });
			$('#op-epm-out').textContent = JSON.stringify(r, null, 2);
		});
		$('#op-epm-dry').onclick = () => epm(true);
		$('#op-epm-run').onclick = () => confirm('Import these phones into OpenProv?') && epm(false);
	}

	// ---- boot ----------------------------------------------------------------
	api('info').then((info) => { cache.info = info; return templates(); }).catch(fail);
})();
