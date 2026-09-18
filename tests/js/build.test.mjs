/**
 * The compiled AMD modules must be valid, current builds of the sources: Moodle
 * loads amd/build/*.min.js, never amd/src/*.js.
 */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {makeDom} from './helpers.mjs';

const read = (name) => fs.readFileSync(new URL(`../../${name}`, import.meta.url), 'utf8');

const speechBuild = read('amd/build/speech.min.js');
const piperBuild = read('amd/build/piper.min.js');
const speechSource = read('amd/src/speech.js');
const piperSource = read('amd/src/piper.js');

/**
 * Load the built modules through a minimal AMD loader that resolves the
 * relative dependency between them the way RequireJS does.
 *
 * @return {Object} Modules by id, each {id, dependencies, exports}.
 */
const loadBuild = () => {
    const dom = makeDom('<!doctype html><html><body></body></html>');
    const modules = {};

    const resolve = (id, dependency) => {
        if (dependency.slice(0, 2) !== './') {
            return dependency;
        }
        return id.split('/').slice(0, -1).concat(dependency.slice(2)).join('/');
    };

    dom.window.define = (id, dependencies, factory) => {
        const exports = {};
        const args = dependencies.map((dependency) => (
            dependency === 'exports' ? exports : (modules[resolve(id, dependency)] || {}).exports
        ));
        factory(...args);
        modules[id] = {id, dependencies: [...dependencies], exports};
    };

    dom.window.eval(piperBuild);
    dom.window.eval(speechBuild);
    return modules;
};

test('the builds declare the module ids Moodle asks for', () => {
    const modules = loadBuild();
    assert.deepEqual(Object.keys(modules).sort(), [
        'block_ahotts_embhl/piper',
        'block_ahotts_embhl/speech',
    ]);
    assert.deepEqual(modules['block_ahotts_embhl/speech'].dependencies, ['exports', './piper']);
});

test('the speech build exports init and works', () => {
    const modules = loadBuild();
    const speech = modules['block_ahotts_embhl/speech'].exports;
    assert.equal(typeof speech.init, 'function');
    assert.equal(
        speech.__testing.originAllowed('https://scorm.example.org', ['https://*.example.org']),
        true
    );
});

test('the piper build exports a working phoneme mapper', () => {
    const modules = loadBuild();
    const piper = modules['block_ahotts_embhl/piper'].exports;
    assert.equal(typeof piper.createPiperEngine, 'function');
    assert.deepEqual(
        [...piper.phonemesToIds('a', {'^': [1], $: [2], _: [0], a: [10]})],
        [1, 0, 10, 0, 2]
    );
});

test('the builds are up to date with the sources', () => {
    const modules = loadBuild();

    const surface = (source) => {
        const match = source.match(/export const __testing = \{([\s\S]*?)\};/);
        return match ? match[1].split(',').map((n) => n.trim()).filter(Boolean) : [];
    };
    surface(speechSource).forEach((name) => {
        assert.ok(
            name in modules['block_ahotts_embhl/speech'].exports.__testing,
            `${name} is missing from amd/build/speech.min.js`
        );
    });

    // Every value piper.js exports has to survive the build.
    const exported = [...piperSource.matchAll(/^export const (\w+)/gm)].map((m) => m[1]);
    assert.ok(exported.length >= 4, 'piper.js exports something');
    exported.forEach((name) => {
        assert.ok(
            name in modules['block_ahotts_embhl/piper'].exports,
            `${name} is missing from amd/build/piper.min.js`
        );
    });

    assert.match(speechBuild, /ahotts-scorm-bridge/, 'the build contains the bridge protocol');
});
