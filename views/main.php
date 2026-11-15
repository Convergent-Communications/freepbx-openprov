<?php
/** @var array $info */
$web = $info['web'];
?>
<div class="container-fluid openprov" id="openprov-app" data-version="<?= htmlspecialchars($info['version']) ?>">
	<div class="op-header">
		<h1>OpenProv <small class="text-muted">phone provisioning <?= htmlspecialchars($info['version']) ?></small></h1>
		<?php if (!empty($web['legacy']['present'])): ?>
			<div class="alert alert-danger">
				The OpenProv 0.1 Apache vhost is still installed (<code>/etc/apache2/sites-enabled/openprov.conf</code>, port <?= htmlspecialchars(implode(', ', $web['legacy']['ports']) ?: '?') ?>).
				OpenProv no longer uses it, and it can stop Apache from starting if another service takes that port.
				Remove it: run <code><?= htmlspecialchars($web['command']) ?></code> as root on the PBX.
			</div>
		<?php endif; ?>
		<?php if (!$web['published']): ?>
			<div class="alert alert-warning">
				Phones can't reach OpenProv: <code><?= htmlspecialchars($web['link']) ?></code> is missing.
				Run <code><?= htmlspecialchars($web['command']) ?></code> on the PBX.
			</div>
		<?php endif; ?>
	</div>
	<div class="fpbx-container">
	<ul class="nav nav-tabs" role="tablist">
		<li class="nav-item"><a class="nav-link active" href="#" data-tab="templates">Templates</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="devices">Phones</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="features">Features</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="assets">Files</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="discover">Discover</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="events">Activity</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="dashboard">Dashboard</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="settings">Settings</a></li>
	</ul>
	<div class="tab-content display op-panel">
		<div id="op-flash"></div>
		<div id="op-main" class="op-main">Loading…</div>
	</div>
	</div>
</div>
