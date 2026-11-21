#!/usr/bin/env node
/**
 * Fails when UI text is written in the frontend instead of lang files (CLAUDE.md: all UI text lives in
 * apps/api/lang/<locale>/*.php and is read through t()). Deliberately simple, not a parser:
 *
 *   1. Arabic characters anywhere in resources/js (.vue and .js).
 *   2. In a .vue <template>: a text node that holds letters, e.g. <p>Save changes</p>.
 *   3. In a .vue <template>: a static attribute that carries UI text (title="Save", placeholder="Search").
 *      Bound attributes (:title="t('x')") and key attributes (label-key="x.y") are fine.
 *   4. In a .vue <template>: a quoted sentence (letters plus a space) inside {{ }} or a bound attribute,
 *      once t('...') calls are removed.
 *
 * Usage: node scripts/check-i18n.mjs [directory]   (default apps/api/resources/js)
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

const root = resolve(process.argv[2] ?? 'apps/api/resources/js');

/** Literal text that is allowed to appear as-is in a template (symbols, units, proper names). */
const ALLOWED_TEXT = new Set(['E2E']);

/** Attributes whose static value is read by people. */
const UI_ATTRIBUTES = ['title', 'label', 'placeholder', 'description', 'aria-label', 'alt', 'hint', 'error', 'caption', 'content', 'tooltip', 'empty-title', 'empty-description'];

const ARABIC = /[؀-ۿݐ-ݿﭐ-﷿ﹰ-﻿]/;
const LETTERS = /[A-Za-z]{2,}/;

function* files(dir) {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);

        if (statSync(path).isDirectory()) {
            yield* files(path);
        } else if (/\.(vue|js)$/.test(name)) {
            yield path;
        }
    }
}

/** 'border-line bg-surface hover:bg-wash' is a class list, not a sentence: every token has a hyphen, colon or slash. */
const isClassList = (text) => text.trim().split(/\s+/).every((token) => /[-:/]/.test(token));

const lineOf = (source, index) => source.slice(0, index).split('\n').length;
const problems = [];
const report = (file, source, index, message, snippet) => problems.push(`${relative(process.cwd(), file)}:${lineOf(source, index)}  ${message}: ${snippet.trim().slice(0, 80)}`);

function templateOf(source) {
    const start = source.search(/<template[\s>]/);
    const end = source.lastIndexOf('</template>');

    return start === -1 || end === -1 ? null : { text: source.slice(start, end), offset: start };
}

for (const file of files(root)) {
    const source = readFileSync(file, 'utf8');
    const arabic = source.match(ARABIC);

    if (arabic) {
        report(file, source, arabic.index, 'Arabic text in code, move it to lang', source.slice(arabic.index, arabic.index + 40));
    }

    if (!file.endsWith('.vue')) {
        continue;
    }

    const template = templateOf(source);

    if (!template) {
        continue;
    }

    // Comments and interpolations are handled separately from the markup around them.
    const markup = template.text.replace(/<!--[\s\S]*?-->/g, (match) => ' '.repeat(match.length));

    for (const match of markup.matchAll(/>([^<>]+)</g)) {
        const text = match[1].replace(/\{\{[\s\S]*?\}\}/g, ' ').trim();

        if (text && LETTERS.test(text) && !ALLOWED_TEXT.has(text)) {
            report(file, source, template.offset + match.index, 'Text in template, use t()', text);
        }
    }

    const staticAttribute = new RegExp(`(?<![:\\w-])(${UI_ATTRIBUTES.join('|')})="([^"]*)"`, 'g');

    for (const match of markup.matchAll(staticAttribute)) {
        if (LETTERS.test(match[2]) && !ALLOWED_TEXT.has(match[2])) {
            report(file, source, template.offset + match.index, `Static ${match[1]} attribute, use :${match[1]}="t(...)"`, match[0]);
        }
    }

    const expressions = [
        ...[...markup.matchAll(/\{\{([\s\S]*?)\}\}/g)].map((match) => [match[1], match.index]),
        ...[...markup.matchAll(/(?:^|\s)(?::|v-bind:|v-if=|v-else-if=|@)[\w.:-]*="([^"]*)"/g)].map((match) => [match[1], match.index]),
    ];

    for (const [expression, index] of expressions) {
        const withoutKeys = expression.replace(/\bt\(\s*(['"`])[^'"`]*\1[^)]*\)/g, '');

        for (const literal of withoutKeys.matchAll(/(['"])((?:(?!\1).)*?)\1/g)) {
            if (LETTERS.test(literal[2]) && /\s/.test(literal[2].trim()) && !isClassList(literal[2]) && !ALLOWED_TEXT.has(literal[2])) {
                report(file, source, template.offset + index, 'Sentence literal in template expression, use t()', literal[0]);
            }
        }
    }
}

if (problems.length) {
    console.error(`check-i18n: ${problems.length} hard-coded UI string(s) found.\n\n${problems.join('\n')}\n\nPut the text in apps/api/lang/{ar,en}/*.php and read it with t('group.key').`);
    process.exit(1);
}

console.log('check-i18n: no hard-coded UI strings in', relative(process.cwd(), root));
