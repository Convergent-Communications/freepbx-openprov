'use strict';
/**
 * OpenProv HTTPS service (DESIGN.md D3), for FreePBX systems whose web server
 * has no HTTPS port. Runs under FreePBX's pm2 module as "openprov-tls"
 * (src/TlsService.php), like UCP's and Sangoma Connect's Node services.
 *
 * It terminates TLS on its own port with Certificate Manager's default
 * certificate and forwards /openprov/... to Apache over loopback HTTP, adding
 * the phone's address and a shared secret (htdocs/index.php trusts those
 * only from loopback with the secret). It is a separate process: if its port
 * is taken it reports that and retries, and Apache is never affected.
 *
 * Node built-ins only. Config: JSON file named by OPENPROV_TLS_CONFIG
 * {port, upstreamPort, secret, cert, key, ca?, statusFile}.
 */
const fs = require('fs');
const http = require('http');
const https = require('https');

const cfgPath = process.env.OPENPROV_TLS_CONFIG;
if (!cfgPath) {
	console.error('OPENPROV_TLS_CONFIG is not set');
	process.exit(1);
}
const cfg = JSON.parse(fs.readFileSync(cfgPath, 'utf8'));
const PREFIX = '/openprov';
const RETRY_MS = 30000;

function log(msg) {
	console.log(`openprov-tls: ${msg}`);
}

function status(fields) {
	const s = Object.assign({ pid: process.pid, port: cfg.port, at: Math.floor(Date.now() / 1000) }, fields);
	try {
		fs.writeFileSync(cfg.statusFile, JSON.stringify(s));
	} catch (e) {
		log(`cannot write ${cfg.statusFile}: ${e.message}`);
	}
}

function tlsOptions() {
	const o = { cert: fs.readFileSync(cfg.cert), key: fs.readFileSync(cfg.key), minVersion: 'TLSv1.2' };
	if (cfg.ca) {
		o.ca = fs.readFileSync(cfg.ca);
	}
	return o;
}

// Hop-by-hop headers are not forwarded either way.
const HOP = ['connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade', 'te', 'trailer'];

function handler(req, res) {
	const path = req.url || '/';
	if (path !== PREFIX && !path.startsWith(PREFIX + '/')) {
		res.writeHead(404, { 'Content-Type': 'text/plain' });
		res.end('not found\n');
		return;
	}
	const headers = {};
	for (const [k, v] of Object.entries(req.headers)) {
		if (!HOP.includes(k) && !['x-forwarded-for', 'x-openprov-proxy', 'x-openprov-port'].includes(k)) {
			headers[k] = v;
		}
	}
	headers['x-forwarded-for'] = String(req.socket.remoteAddress || '').replace(/^::ffff:/, '');
	headers['x-openprov-proxy'] = cfg.secret;
	// The port the phone connected to: index.php answers internet addresses
	// only on internet_ports (443).
	headers['x-openprov-port'] = String(req.socket.localPort || 0);
	const up = http.request({ host: '127.0.0.1', port: cfg.upstreamPort, method: req.method, path, headers }, (ur) => {
		const out = {};
		for (const [k, v] of Object.entries(ur.headers)) {
			if (!HOP.includes(k)) {
				out[k] = v;
			}
		}
		res.writeHead(ur.statusCode || 502, out);
		ur.pipe(res);
	});
	up.setTimeout(15000, () => up.destroy(new Error('upstream timeout')));
	up.on('socket', (sock) => sock.once('connect', () => up.setTimeout(0))); // firmware streams for minutes
	up.on('error', (e) => {
		log(`upstream error: ${e.message}`);
		if (!res.headersSent) {
			res.writeHead(502, { 'Content-Type': 'text/plain' });
		}
		res.end();
	});
	req.pipe(up);
}

let server;
try {
	server = https.createServer(tlsOptions(), handler);
} catch (e) {
	status({ ok: false, error: `cannot load the certificate (${cfg.cert}): ${e.message}` });
	log(`cannot load the certificate: ${e.message}`);
	// Stay up (pm2 would restart-loop otherwise) and retry: certman may fix it.
	setTimeout(() => process.exit(1), RETRY_MS);
	return;
}

function listen() {
	server.listen({ port: cfg.port, host: '::', ipv6Only: false });
}

server.on('listening', () => {
	status({ ok: true, error: '' });
	log(`listening on ${cfg.port}, forwarding to 127.0.0.1:${cfg.upstreamPort}${PREFIX}`);
});

server.on('error', (e) => {
	if (e.code === 'EAFNOSUPPORT' || e.code === 'EADDRNOTAVAIL') {
		server.listen({ port: cfg.port, host: '0.0.0.0' }); // no IPv6 on this host
		return;
	}
	const why = e.code === 'EADDRINUSE' ? `port ${cfg.port} is already in use by another process`
		: e.code === 'EACCES' ? `not allowed to bind port ${cfg.port}`
		: e.message;
	status({ ok: false, error: why });
	log(`${why}; retrying in ${RETRY_MS / 1000}s`);
	setTimeout(listen, RETRY_MS);
});

// Let's Encrypt renewals rewrite the same files: pick them up without a restart.
for (const f of [cfg.cert, cfg.key, cfg.ca].filter(Boolean)) {
	fs.watchFile(f, { interval: 60000 }, () => {
		try {
			server.setSecureContext(tlsOptions());
			log('certificate reloaded');
		} catch (e) {
			log(`certificate reload failed, keeping the old one: ${e.message}`);
		}
	});
}

listen();
