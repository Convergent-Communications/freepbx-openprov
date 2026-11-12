<?php
/** @var array $info */
$web = $info['web'];
?>
<div class="container-fluid openprov" id="openprov-app" data-version="<?= htmlspecialchars($info['version']) ?>">
	<div class="op-header">
		<h1>OpenProv <small class="text-muted">phone provisioning <?= htmlspecialchars($info['version']) ?></small></h1>
		<?php if (!$web['installed'] || !$web['current']): ?>
			<div class="alert alert-warning">
				The provisioning web server is <?= $web['installed'] ? 'out of date' : 'not installed' ?>.
				Run <code><?= htmlspecialchars($web['command']) ?></code> as root on the PBX.
			</div>
		<?php endif; ?>
	</div>
	<ul class="nav nav-tabs" role="tablist">
		<li class="nav-item"><a class="nav-link active" href="#" data-tab="templates">Templates</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="devices">Phones</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="features">Features</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="assets">Files</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="discover">Discover</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="events">Activity</a></li>
		<li class="nav-item"><a class="nav-link" href="#" data-tab="settings">Settings</a></li>
	</ul>
	<div id="op-flash"></div>
	<div id="op-main" class="op-main">Loading…</div>
</div>
