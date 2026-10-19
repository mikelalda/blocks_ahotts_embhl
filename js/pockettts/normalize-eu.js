// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Basque text normalisation for speech synthesis.
 *
 * Pocket TTS reads letters only: digits, symbols and abbreviations have to be
 * spelled out first. This turns them into the words a Basque speaker would say:
 *
 *   2024ko urriaren 19an      -> bi mila eta hogeita lauko urriaren hemeretzian
 *   3. mailan, %25            -> hirugarren mailan, ehuneko hogeita bost
 *   1,5 km, 10:30, 2024/10/19 -> bat koma bost kilometro, hamarrak eta erdiak, ...
 *
 * Numbers follow the Basque vigesimal system (hogei, berrogei, hirurogei,
 * laurogei) and put "eta" once, before the last component (mila bederatziehun
 * eta laurogeita hamar; bi mila eta hogeita lau; mila eta berrehun).
 *
 * Case endings written straight onto a number (2024an, 1990eko, 10ean, 3ra) are
 * kept and attached to the spelled-out word, doubling a final r before a vowel
 * as the language does (hamar + ean -> hamarrean).
 */

const UNITS = ['zero', 'bat', 'bi', 'hiru', 'lau', 'bost', 'sei', 'zazpi', 'zortzi', 'bederatzi',
    'hamar', 'hamaika', 'hamabi', 'hamahiru', 'hamalau', 'hamabost', 'hamasei', 'hamazazpi',
    'hemezortzi', 'hemeretzi'];
const TWENTIES = ['', 'hogei', 'berrogei', 'hirurogei', 'laurogei'];
const HUNDREDS = ['', 'ehun', 'berrehun', 'hirurehun', 'laurehun', 'bostehun', 'seiehun',
    'zazpiehun', 'zortziehun', 'bederatziehun'];
const MONTHS = ['urtarrila', 'otsaila', 'martxoa', 'apirila', 'maiatza', 'ekaina', 'uztaila',
    'abuztua', 'iraila', 'urria', 'azaroa', 'abendua'];

// Read digit by digit past this, as phone or account numbers are.
const MAX_DIGITS = 12;

/**
 * 0..99.
 *
 * @param {number} n
 * @return {string}
 */
const belowHundred = (n) => {
    if (n < 20) {
        return UNITS[n];
    }
    const score = Math.floor(n / 20);
    const rest = n % 20;
    return rest === 0 ? TWENTIES[score] : TWENTIES[score] + 'ta ' + UNITS[rest];
};

/**
 * 1..999999, the hundreds and thousands with the single "eta".
 *
 * @param {number} n
 * @return {string}
 */
const belowMillion = (n) => {
    const thousands = Math.floor(n / 1000);
    const rest = n % 1000;
    const hundreds = Math.floor(rest / 100);
    const below = rest % 100;

    const parts = [];
    if (thousands) {
        parts.push(thousands === 1 ? 'mila' : belowMillion(thousands) + ' mila');
    }
    if (below) {
        if (hundreds) {
            parts.push(HUNDREDS[hundreds]);
        }
        if (parts.length) {
            parts.push('eta');
        }
        parts.push(belowHundred(below));
    } else if (hundreds) {
        if (parts.length) {
            parts.push('eta');
        }
        parts.push(HUNDREDS[hundreds]);
    }
    return parts.join(' ');
};

/**
 * A whole number in words.
 *
 * @param {number|string} value Non-negative integer.
 * @return {string}
 */
export const cardinal = (value) => {
    const n = Number(value);
    if (!Number.isSafeInteger(n) || n < 0) {
        return String(value);
    }
    if (n === 0) {
        return 'zero';
    }
    if (n < 1e6) {
        return belowMillion(n);
    }
    const millions = Math.floor(n / 1e6);
    const rest = n % 1e6;
    const head = millions === 1 ? 'milioi bat' : belowMillion(millions) + ' milioi';
    if (!rest) {
        return head;
    }
    const tail = belowMillion(rest);
    return head + (tail.includes(' eta ') ? ' ' : ' eta ') + tail;
};

/**
 * An ordinal: 1 lehen, 2 bigarren, 5 bosgarren, 21 hogeita batgarren.
 *
 * @param {number|string} value
 * @return {string}
 */
export const ordinal = (value) => {
    const n = Number(value);
    if (n === 1) {
        return 'lehen';
    }
    return cardinal(n).replace(/bost$/, 'bos') + 'garren';
};

/**
 * Attach a case ending to a spelled-out number.
 *
 * The writer already chose the ending for the spoken word (10ean, 2000n), so it
 * is kept as written; only the r doubling and a repeated a are fixed.
 *
 * @param {string} word
 * @param {string} ending
 * @return {string}
 */
export const attach = (word, ending) => {
    if (!ending) {
        return word;
    }
    ending = ending.toLowerCase();
    if (/r$/.test(word) && /^[aeiou]/.test(ending)) {
        return word + 'r' + ending;
    }
    if (/a$/.test(word) && /^a/.test(ending)) {
        return word + ending.slice(1);
    }
    return word + ending;
};

/** The -ko of a year: 2024ko, 2001eko, 1990eko. */
const withKo = (word) => {
    if (/[aeiou]$/.test(word)) {
        return word + 'ko';
    }
    return attach(word, 'eko');
};

/** The -a of a day: 19a, 1a, 10a. */
const withArticle = (word) => attach(word, 'a');

/** Digits read one at a time, for codes and very long numbers. */
const digits = (text) => Array.from(text, (d) => UNITS[Number(d)]).join(' ');

/**
 * Digits as words, whatever their size or leading zeros.
 *
 * @param {string} text Digits only.
 * @return {string}
 */
const number = (text) => {
    if (text.length > MAX_DIGITS || (text.length > 1 && text[0] === '0')) {
        return digits(text);
    }
    return cardinal(text);
};

const decimal = (whole, fraction) => {
    // Leading zeros after the comma are spoken: 0,05 is zero koma zero bost.
    const zeros = fraction.match(/^0*/)[0].length;
    const rest = fraction.slice(zeros);
    const spoken = [];
    for (let i = 0; i < zeros; i++) {
        spoken.push('zero');
    }
    if (rest) {
        spoken.push(rest.length > 3 ? digits(rest) : cardinal(rest));
    }
    return number(whole) + ' koma ' + spoken.join(' ');
};

/** Strip thousands separators: 1.000.000 and 1 000 000. */
const plain = (text) => text.replace(/[.\s  ]/g, '');

const romanValue = (roman) => {
    const values = {I: 1, V: 5, X: 10, L: 50, C: 100, D: 500, M: 1000};
    let total = 0;
    for (let i = 0; i < roman.length; i++) {
        const v = values[roman[i]];
        const next = values[roman[i + 1]] || 0;
        total += v < next ? -v : v;
    }
    return total;
};

const date = (day, month, year) => {
    const d = Number(day);
    const m = Number(month);
    if (m < 1 || m > 12 || d < 1 || d > 31) {
        return null;
    }
    return withKo(cardinal(year)) + ' ' + MONTHS[m - 1] + 'ren ' + withArticle(cardinal(d));
};

/**
 * The hour, as a stem that takes the article or a case ending.
 *
 * hiru and lau take an r before a vowel (hirurak, lauretan); 1 and 2 are
 * ordubat and ordubi.
 */
const hourStem = (h) => {
    if (h === 1) {
        return 'ordubat';
    }
    if (h === 2) {
        return 'ordubi';
    }
    return cardinal(h);
};

/** An hour with the article or an ending: hirurak, lauretan, hamarrak, ordubietan. */
const hourWith = (stem, ending) => (/(hiru|lau)$/.test(stem) && /^[aeiou]/.test(ending))
    ? stem + 'r' + ending
    : attach(stem, ending);

const time = (h, m, ending) => {
    const hh = Number(h);
    const mm = Number(m);
    if (hh > 24 || mm > 59) {
        return null;
    }
    const twelve = hh === 0 ? 12 : (hh > 12 ? hh - 12 : hh);
    const stem = hourStem(twelve);
    // ordubata is singular; every other hour is plural (hamarrak).
    const head = twelve === 1 ? 'ordubata' : hourWith(stem, 'ak');
    // "At" a time, however it was typed (10:00etan, 10:30ean), is the plural locative.
    const locative = !!ending && /^(e?tan|e?an|n)$/.test(ending);

    if (mm === 0) {
        if (!ending) {
            return head;
        }
        if (locative) {
            return hourWith(stem, twelve === 1 ? 'ean' : 'etan');
        }
        return hourWith(stem, ending);
    }
    if (mm === 30) {
        return head + ' eta ' + attach('erdi', locative ? 'etan' : (ending || 'ak'));
    }
    if (mm === 15) {
        return head + ' eta ' + attach('laurden', locative ? 'ean' : (ending || ''));
    }
    return head + ' eta ' + attach(cardinal(mm), ending || '');
};

// Units spoken after a number. The noun comes before "bat": euro bat, kilometro bat.
const UNIT_WORDS = {
    '€': 'euro', 'eur': 'euro', 'euro': 'euro', '$': 'dolar',
    'km/h': 'kilometro orduko', 'km': 'kilometro', 'm': 'metro', 'cm': 'zentimetro', 'mm': 'milimetro',
    'kg': 'kilo', 'g': 'gramo', 'mg': 'miligramo', 'l': 'litro', 'ml': 'mililitro',
    'ºC': 'gradu', '°C': 'gradu', 'º': 'gradu', '°': 'gradu',
    'h': 'ordu', 'min': 'minutu', 's': 'segundo',
};

const quantity = (amount, unit, ending) => {
    const word = UNIT_WORDS[unit] || UNIT_WORDS[unit.toLowerCase()];
    const spoken = /^1$/.test(amount) ? word + ' bat' : amount + ' ' + word;
    // An ending belongs to the last word: 25 km-ko -> hogeita bost kilometroko.
    return ending ? attach(spoken, ending) : spoken;
};

// Abbreviations common in teaching material.
const ABBREVIATIONS = [
    [/\betab\./g, 'eta abar'],
    [/\badib\./g, 'adibidez'],
    [/\bzk\./g, 'zenbakia'],
    [/\borr\./g, 'orrialdea'],
    [/\bK\.a\./g, 'Kristo aurretik'],
    [/\bK\.o\./g, 'Kristo ondoren'],
    [/\bIk\./g, 'Ikus'],
    [/\bik\./g, 'ikus'],
];

// A case ending written on a number, optionally after a hyphen: 2024an, 1990-eko.
const ENDING = '(?:-?([a-zñ]{1,8}))?';
// After a unit symbol the ending needs its hyphen (25 km-ko); "3 mendi" is not metres.
const UNIT_ENDING = '(?:-([a-zñ]{1,8}))?';
const NOT_WORD = '(?![\\p{L}\\p{N}])';
const re = (source) => new RegExp(source, 'gu');

/**
 * Spell out numbers, dates, times, units and abbreviations in Basque text.
 *
 * @param {string} text
 * @return {string}
 */
export const normalizeBasque = (text) => {
    let out = String(text);

    for (const [pattern, words] of ABBREVIATIONS) {
        out = out.replace(pattern, words);
    }

    // Centuries: XX. mendea -> hogeigarren mendea.
    out = out.replace(/\b([IVXLC]+)\.\s+(mende)/g, (m, roman, word) => ordinal(romanValue(roman)) + ' ' + word);

    // Dates: 2024/10/19, 2024-10-19, 19/10/2024, 19-10-2024.
    out = out.replace(re('(?<![\\p{N}])(\\d{4})[/-](\\d{1,2})[/-](\\d{1,2})' + NOT_WORD),
        (m, y, mo, d) => date(d, mo, y) ?? m);
    out = out.replace(re('(?<![\\p{N}])(\\d{1,2})[/.-](\\d{1,2})[/.-](\\d{4})' + NOT_WORD),
        (m, d, mo, y) => date(d, mo, y) ?? m);

    // Times: 10:30, 9:05, 15:00etan.
    out = out.replace(re('(?<![\\p{N}:])(\\d{1,2}):(\\d{2})' + ENDING + NOT_WORD),
        (m, h, mi, ending) => time(h, mi, (ending || '').toLowerCase()) ?? m);

    // Percentages: %25, % 25, 25%, 25 %, with an optional ending (%25eko).
    out = out.replace(re('%\\s?(\\d+(?:,\\d+)?)' + ENDING + NOT_WORD),
        (m, n, ending) => attach('ehuneko ' + n, ending || ''));
    out = out.replace(re('(?<![\\p{N}])(\\d+(?:,\\d+)?)\\s?%' + ENDING),
        (m, n, ending) => attach('ehuneko ' + n, ending || ''));

    // Amounts with a unit: 25 €, 25€, 1,5 km, 3 kg-ko.
    const units = Object.keys(UNIT_WORDS).sort((a, b) => b.length - a.length)
        .map((u) => u.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&')).join('|');
    out = out.replace(re('(?<![\\p{L}\\p{N}])(\\d+(?:[.,]\\d+)*)\\s?(' + units + ')' + UNIT_ENDING + NOT_WORD),
        (m, n, unit, ending) => quantity(n, unit, ending || ''));
    out = out.replace(re('(?<![\\p{L}\\p{N}])€\\s?(\\d+(?:[.,]\\d+)*)'), (m, n) => quantity(n, '€', ''));

    // Ordinals: a number and a full stop followed by a lower-case word (3. maila).
    out = out.replace(re('(?<![\\p{N}.,])(\\d+)\\.(?=\\s+\\p{Ll})'), (m, n) => ordinal(n));

    // Ranges and fractions read with a pause or "zati": 2024-2025, 1/2.
    out = out.replace(re('(?<![\\p{N}])(\\d+)-(\\d+)' + NOT_WORD), '$1, $2');
    out = out.replace(re('(?<![\\p{N}/])(\\d+)/(\\d+)' + NOT_WORD), '$1 zati $2');

    // Negative numbers.
    out = out.replace(re('(^|[\\s(])[-−](\\d)'), '$1minus $2');

    // Numbers with thousands separators: 1.000, 25.000.000, 10 000.
    out = out.replace(re('(?<![\\p{N},.])(\\d{1,3}(?:[.\\u00a0\\u202f]\\d{3})+)(?![.,]?\\d)' + ENDING),
        (m, n, ending) => attach(number(plain(n)), ending || ''));

    // Decimals: 3,5 (and 3.5, as typed on an English keyboard).
    out = out.replace(re('(?<![\\p{N}])(\\d+)[,.](\\d+)' + ENDING + NOT_WORD),
        (m, w, f, ending) => attach(decimal(w, f), ending || ''));

    // Whatever digits remain, with their ending.
    out = out.replace(re('(\\d+)' + ENDING),
        (m, n, ending) => attach(number(n), ending || ''));

    // Symbols left on their own.
    out = out.replace(/\s&\s/g, ' eta ').replace(/\s\+\s/g, ' gehi ').replace(/\s=\s/g, ' berdin ');

    return out.replace(/[ \t]{2,}/g, ' ');
};
