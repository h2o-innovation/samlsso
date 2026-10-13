#!/usr/bin/env node
/**
 *  ------------------------------------------------------------------------
 *  samlSSO
 *
 *  semantic-release prepare hook.
 *
 *  Keeps the plugin's own versioning conventions in sync with the version
 *  computed by semantic-release:
 *    1. bumps PLUGIN_SAMLSSO_VERSION in setup.php;
 *    2. bumps the @version tag of every PHP file (except vendor/node_modules);
 *    3. appends the new <version> block to samlsso.xml (marketplace manifest);
 *    4. prepends a new entry to changelog.md in the project's existing format;
 *    5. builds release/samlsso.zip (GLPI plugin layout: samlsso/...).
 *
 *  Invoked from .releaserc.json via @semantic-release/exec:
 *    node tools/semantic-release/prepare.js ${nextRelease.version}
 *
 *  Copyright (C) 2026 by DonutsNL
 *  ------------------------------------------------------------------------
 **/

'use strict';

const { execSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const version = (process.argv[2] || '').trim();
if (!/^\d+\.\d+\.\d+/.test(version)) {
    console.error(`[prepare] Missing or invalid version argument: "${version}"`);
    process.exit(1);
}

const root = path.resolve(__dirname, '..', '..');
const repo = process.env.GITHUB_REPOSITORY || 'h2o-innovation/samlsso';
const zipUrl = `https://github.com/${repo}/releases/download/v${version}/samlsso.zip`;

/**
 * Recursively walk a directory, skipping VCS and dependency folders.
 *
 * @param {string} dir
 * @param {(file: string) => void} cb
 */
function walk(dir, cb) {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (['.git', 'vendor', 'node_modules', 'release', 'docs'].includes(entry.name)) {
            continue;
        }
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            walk(full, cb);
        } else {
            cb(full);
        }
    }
}

// 1. Bump PLUGIN_SAMLSSO_VERSION in setup.php.
const setupPath = path.join(root, 'setup.php');
const setup = fs.readFileSync(setupPath, 'utf8');
if (!/define\('PLUGIN_SAMLSSO_VERSION',\s*'[^']+'\)/.test(setup)) {
    console.error('[prepare] PLUGIN_SAMLSSO_VERSION not found in setup.php');
    process.exit(1);
}
fs.writeFileSync(
    setupPath,
    setup.replace(
        /define\('PLUGIN_SAMLSSO_VERSION',\s*'[^']+'\)/,
        `define('PLUGIN_SAMLSSO_VERSION', '${version}')`
    )
);

// 2. Align the @version tag of every PHP file with the released version.
let bumped = 0;
walk(root, (file) => {
    if (!file.endsWith('.php')) {
        return;
    }
    const content = fs.readFileSync(file, 'utf8');
    if (!/@version\s+\d+\.\d+\.\d+/.test(content)) {
        return;
    }
    const updated = content.replace(/(@version\s+)\d+\.\d+\.\d+/g, `$1${version}`);
    if (updated !== content) {
        fs.writeFileSync(file, updated);
        bumped++;
    }
});

// 3. Append the new <version> block to samlsso.xml.
const xmlPath = path.join(root, 'samlsso.xml');
const xml = fs.readFileSync(xmlPath, 'utf8');
if (!xml.includes(`<num>${version}</num>`)) {
    const block =
        `    <version>\n` +
        `        <num>${version}</num>\n` +
        `        <compatibility>~11.0.0</compatibility>\n` +
        `        <download_url>${zipUrl}</download_url>\n` +
        `    </version>\n`;
    fs.writeFileSync(xmlPath, xml.replace(/<\/versions>/, `${block}</versions>`));
}

// 4. Prepend a changelog entry generated from the commit history.
const labelByType = {
    feat: 'Feature',
    fix: 'Fix',
    perf: 'Performance',
    refactor: 'Refactor',
    revert: 'Revert',
    docs: 'Docs',
    test: 'Tests',
    build: 'Build',
    ci: 'CI',
    style: 'Style',
    chore: 'Chore'
};
const labelOrder = ['Feature', 'Fix', 'Performance', 'Refactor', 'Revert', 'Docs', 'Tests', 'Build', 'CI', 'Style', 'Chore'];

let range = '';
try {
    const prevTag = execSync('git describe --tags --abbrev=0', { cwd: root, stdio: ['ignore', 'pipe', 'ignore'] })
        .toString()
        .trim();
    if (prevTag) {
        range = `${prevTag}..HEAD`;
    }
} catch (e) {
    // No previous tag yet: use the full history.
}

const subjects = execSync(`git log ${range} --no-merges --pretty=%s`, { cwd: root })
    .toString()
    .split('\n')
    .map((line) => line.trim())
    .filter(Boolean)
    .filter((line) => !/^chore\(release\)/.test(line));

const grouped = new Map();
for (const subject of subjects) {
    const match = subject.match(/^([a-zA-Z]+)(?:\([^)]*\))?!?:\s*(.+)$/);
    let label = 'Chore';
    let text = subject;
    if (match) {
        label = labelByType[match[1].toLowerCase()] || 'Chore';
        text = match[2];
    }
    if (!grouped.has(label)) {
        grouped.set(label, []);
    }
    grouped.get(label).push(text);
}

const lines = [];
for (const label of labelOrder) {
    if (grouped.has(label)) {
        for (const text of grouped.get(label)) {
            lines.push(`- ${label}: ${text}`);
        }
    }
}

const changelogPath = path.join(root, 'changelog.md');
const existing = fs.readFileSync(changelogPath, 'utf8').replace(/^\n+/, '');
const entry = `**V${version}**\n${lines.join('\n')}\n`;
fs.writeFileSync(changelogPath, `${entry}\n${existing}`);

// 5. Build the release zip with the GLPI plugin layout (samlsso/...).
const excludeDirs = new Set(['.git', '.github', '.vscode', 'node_modules', 'release', 'tests', 'tools']);
const excludeFiles = new Set(['samlsso.xml', '.gitignore', 'composer.lock', 'package.json', 'package-lock.json', '.releaserc.json']);
const excludeVendor = new Set(['bin', 'myclabs', 'nikic', 'phar-io', 'phpunit', 'sebastian', 'theseer']);

function copy(srcDir, destDir, rel) {
    for (const entry of fs.readdirSync(srcDir, { withFileTypes: true })) {
        const name = entry.name;
        const relPath = rel ? `${rel}/${name}` : name;
        if (entry.isDirectory()) {
            if (excludeDirs.has(name)) {
                continue;
            }
            if (rel === 'vendor' && excludeVendor.has(name)) {
                continue;
            }
            fs.mkdirSync(path.join(destDir, name), { recursive: true });
            copy(path.join(srcDir, name), path.join(destDir, name), relPath);
            continue;
        }
        if (excludeFiles.has(name)) {
            continue;
        }
        if (/\.(po|pot|po~|mo~)$/.test(name)) {
            continue;
        }
        fs.copyFileSync(path.join(srcDir, name), path.join(destDir, name));
    }
}

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'samlsso-'));
const staging = path.join(tmp, 'samlsso');
fs.mkdirSync(staging, { recursive: true });
copy(root, staging, '');

const out = path.join(root, 'release', 'samlsso.zip');
fs.mkdirSync(path.dirname(out), { recursive: true });
if (fs.existsSync(out)) {
    fs.rmSync(out);
}
execSync(`zip -rq ${JSON.stringify(out)} samlsso`, { cwd: tmp });
fs.rmSync(tmp, { recursive: true, force: true });

console.log(`[prepare] v${version}: ${bumped} PHP headers bumped, changelog + samlsso.xml updated, ${path.relative(root, out)} built.`);
