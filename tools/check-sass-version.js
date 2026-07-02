#!/usr/bin/env node
/*
 * WHAT: prebuild guard — refuses to compile SCSS unless the dart-sass that will
 *       run is EXACTLY the version pinned in package.json.devDependencies.sass.
 * WHY:  the committed compiled CSS is byte-reproducible only under the pinned
 *       sass. A different dart-sass reserializes oklch() (e.g.
 *       `oklch(66% .11 28deg)` <-> `oklch(0.66 .11 28)`) and regroups comma-
 *       selectors, so `npm run build:css` with a stray GLOBAL sass yields a huge
 *       COSMETIC diff that looks like real drift (it isn't). This fires when
 *       node_modules isn't installed (npm falls back to a global sass).
 * FIX WHEN IT TRIPS: run `npm ci` in this dir, then re-run the build.
 * INPUT/OUTPUT: no args; exit 0 = ok to build, exit 1 = wrong/absent sass.
 * DEPENDS ON: the local `sass` devDependency being installed (that's the point).
 */
'use strict';
const path = require('path');
const fs = require('fs');

const root = path.join(__dirname, '..');
const want = require(path.join(root, 'package.json')).devDependencies.sass;

// Read the LOCAL sass package.json straight off disk. We can't `require('sass/
// package.json')` — modern sass declares an `exports` map that forbids that
// subpath (ERR_PACKAGE_PATH_NOT_EXPORTED). Reading the file directly also means
// only THIS plugin's node_modules counts; a global sass can't satisfy the check.
let have = null;
try {
  const pkg = path.join(root, 'node_modules', 'sass', 'package.json');
  have = JSON.parse(fs.readFileSync(pkg, 'utf8')).version;
} catch (_) {
  have = null;
}

if (have !== want) {
  const found = have ? `found ${have}` : 'not installed locally (npm fell back to a global sass)';
  console.error(
    `\nBUILD BLOCKED: dart-sass must be exactly ${want} for a reproducible CSS build, but ${found}.\n` +
    `Run "npm ci" in this dir first, then "npm run build:css".\n` +
    `(A mismatched sass only reserializes oklch()/selector-grouping — a cosmetic diff, not real drift.)\n`
  );
  process.exit(1);
}
