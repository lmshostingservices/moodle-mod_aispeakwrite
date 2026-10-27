// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Speech helpers: the browser's own speech recognition, a microphone level meter and text-to-speech.
 *
 * Nothing here records or uploads audio. Recognition is the browser's feature; only the recognised text is used.
 *
 * @module     mod_aispeakwrite/speech
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition || null;
const AC = window.AudioContext || window.webkitAudioContext || null;

/**
 * Whether this browser can turn speech into text.
 *
 * @returns {boolean}
 */
export const supported = () => Recognition !== null;

/**
 * Listens continuously until stopped, restarting when the browser ends recognition early.
 */
export class Listener {
    /**
     * @param {string} locale BCP-47 language
     * @param {Function} onUpdate called with the text so far
     */
    constructor(locale, onUpdate) {
        this.locale = locale;
        this.onUpdate = onUpdate;
        this.finals = [];
        this.interim = '';
        this.active = false;
        this.failure = null;
        this.rec = null;
        this.resolveStop = null;
    }

    /**
     * Starts listening.
     */
    start() {
        this.finals = [];
        this.interim = '';
        this.failure = null;
        this.active = true;
        this.open();
    }

    /**
     * Opens one recognition session.
     */
    open() {
        const rec = new Recognition();
        rec.lang = this.locale;
        rec.continuous = true;
        rec.interimResults = true;
        rec.maxAlternatives = 1;
        rec.onresult = (e) => {
            let interim = '';
            for (let i = e.resultIndex; i < e.results.length; i++) {
                const text = e.results[i][0].transcript.trim();
                if (e.results[i].isFinal) {
                    if (text !== '') {
                        this.finals.push(text);
                    }
                } else {
                    interim += ' ' + text;
                }
            }
            this.interim = interim.trim();
            this.onUpdate(this.text());
        };
        rec.onerror = (e) => {
            if (e.error === 'not-allowed' || e.error === 'service-not-allowed' || e.error === 'audio-capture') {
                this.failure = e.error;
                this.active = false;
            }
        };
        rec.onend = () => {
            if (this.active) {
                // Browsers end recognition after a silence or a minute; keep listening until the learner stops.
                try {
                    this.open();
                    return;
                } catch (err) {
                    this.active = false;
                }
            }
            if (this.resolveStop) {
                const resolve = this.resolveStop;
                this.resolveStop = null;
                if (this.interim !== '') {
                    this.finals.push(this.interim);
                    this.interim = '';
                }
                resolve(this.text());
            }
        };
        this.rec = rec;
        try {
            rec.start();
        } catch (err) {
            this.failure = 'start';
            this.active = false;
        }
    }

    /**
     * The text recognised so far.
     *
     * @returns {string}
     */
    text() {
        return (this.finals.join(' ') + ' ' + this.interim).replace(/\s+/g, ' ').trim();
    }

    /**
     * Stops listening.
     *
     * @returns {Promise<string>} the final text
     */
    stop() {
        this.active = false;
        return new Promise((resolve) => {
            this.resolveStop = resolve;
            try {
                this.rec.stop();
            } catch (err) {
                this.resolveStop = null;
                resolve(this.text());
            }
            // Some browsers never fire "end" after stop(): give up waiting after two seconds.
            window.setTimeout(() => {
                if (this.resolveStop) {
                    this.resolveStop = null;
                    resolve(this.text());
                }
            }, 2000);
        });
    }
}

/**
 * Opens the microphone and reports its level (0..1) on every animation frame.
 *
 * @param {Function} onLevel
 * @returns {Promise<{stop: Function, level: Function}>}
 */
export const meter = async(onLevel) => {
    const stream = await navigator.mediaDevices.getUserMedia({audio: true});
    if (!AC) {
        return {level: () => 0, stop: () => stream.getTracks().forEach((t) => t.stop())};
    }
    const ctx = new AC();
    const source = ctx.createMediaStreamSource(stream);
    const analyser = ctx.createAnalyser();
    analyser.fftSize = 1024;
    source.connect(analyser);
    const data = new Uint8Array(analyser.fftSize);
    let current = 0;
    let running = true;
    const tick = () => {
        if (!running) {
            return;
        }
        analyser.getByteTimeDomainData(data);
        let sum = 0;
        for (let i = 0; i < data.length; i++) {
            const v = (data[i] - 128) / 128;
            sum += v * v;
        }
        current = Math.min(1, Math.sqrt(sum / data.length) * 4);
        onLevel(current);
        window.requestAnimationFrame(tick);
    };
    tick();
    return {
        level: () => current,
        stop: () => {
            running = false;
            stream.getTracks().forEach((t) => t.stop());
            ctx.close();
        },
    };
};

/**
 * Speaks a text with the browser's own voice.
 *
 * @param {string} text
 * @param {string} locale
 * @returns {Promise<void>} resolves when finished (or at once when the browser has no voices)
 */
export const say = (text, locale) => new Promise((resolve) => {
    if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) {
        resolve();
        return;
    }
    const u = new window.SpeechSynthesisUtterance(text);
    u.lang = locale;
    const want = locale.toLowerCase();
    const base = want.split('-')[0];
    const voices = window.speechSynthesis.getVoices();
    u.voice = voices.find((v) => v.lang.toLowerCase() === want)
        || voices.find((v) => v.lang.toLowerCase().split(/[-_]/)[0] === base) || null;
    u.rate = 0.95;
    u.onend = () => resolve();
    u.onerror = () => resolve();
    window.speechSynthesis.cancel();
    window.speechSynthesis.speak(u);
});

/**
 * Words of a text, lower case, without punctuation.
 *
 * @param {string} text
 * @returns {string[]}
 */
export const words = (text) => text.toLowerCase()
    .replace(/[.,!?;:„“”"'()«»¿¡–-]/g, ' ')
    .split(/\s+/)
    .filter((w) => w !== '');

/**
 * How much of a target sentence was said, in order (0..1).
 *
 * @param {string} heard
 * @param {string} target
 * @returns {number}
 */
export const match = (heard, target) => {
    const a = words(heard);
    const b = words(target);
    if (!b.length) {
        return 0;
    }
    const dp = Array.from({length: a.length + 1}, () => new Array(b.length + 1).fill(0));
    for (let i = 1; i <= a.length; i++) {
        for (let j = 1; j <= b.length; j++) {
            dp[i][j] = a[i - 1] === b[j - 1] ? dp[i - 1][j - 1] + 1 : Math.max(dp[i - 1][j], dp[i][j - 1]);
        }
    }
    return dp[a.length][b.length] / b.length;
};
