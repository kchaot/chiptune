(() => {
  'use strict';

  const root = document.getElementById('daw');
  const projectData = document.getElementById('project');
  const setData = document.getElementById('set');
  const mixData = document.getElementById('mix');

  if (!root || !(projectData || setData || mixData)) return;

  const WAVES = { square: 'square', triangle: 'triangle', saw: 'sawtooth', sine: 'sine' };
  const DRUM_ORDER = ['k', 'c', 's', 'o', 'h'];
  const LOOKAHEAD = 0.6;
  const MIX_LOOKAHEAD = 0.25;

  const pulseCache = new Map();
  let ctx = null;
  let master = null;
  let noise = null;

  function makeState() {
    return {
      rate: 1,
      nudge: 0,
      fx: { low: 0, mid: 0, high: 0, filter: 0, echo: false, beat: 0.5 },
      kill: { low: false, mid: false, high: false },
      hold: { loopBars: 0, roll: 0, version: 0 },
      volume: 1,
      nodes: null,
    };
  }

  const S = makeState();

  try {
    const saved = Number(localStorage.getItem('chiptune-volume'));
    if (localStorage.getItem('chiptune-volume') !== null && saved >= 0 && saved <= 1) S.volume = saved;
  } catch (error) {
    //
  }

  let songGain = null;

  function setSongVolume(value) {
    S.volume = value;

    if (ctx && songGain) songGain.gain.setTargetAtTime(value, ctx.currentTime, 0.02);

    try {
      localStorage.setItem('chiptune-volume', String(value));
    } catch (error) {
      //
    }
  }

  function speed(st) {
    return st.rate * (1 + st.nudge);
  }

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  const SVG_NS = 'http://www.w3.org/2000/svg';

  function svgNode(tag, attributes) {
    const node = document.createElementNS(SVG_NS, tag);

    Object.keys(attributes || {}).forEach(name => node.setAttribute(name, String(attributes[name])));

    return node;
  }

  function percent(value) {
    return (value * 100).toFixed(3) + '%';
  }

  function instrumentLabel(track) {
    if (track.instrument === 'pulse') return 'pulse ' + Math.round(track.pw * 100) + '%';
    return track.instrument;
  }

  function signed(value, digits) {
    return (value > 0 ? '+' : value < 0 ? '\u2212' : '\u00b1') + Math.abs(value).toFixed(digits);
  }

  const KNOBS = [
    { key: 'low', name: 'Tief', min: -12, max: 12, step: 0.5, kill: true },
    { key: 'mid', name: 'Mitte', min: -12, max: 12, step: 0.5, kill: true },
    { key: 'high', name: 'Hoch', min: -12, max: 12, step: 0.5, kill: true },
    { key: 'filter', name: 'Filter', min: -100, max: 100, step: 1 },
  ];

  function knobText(key, value) {
    if (key === 'filter') {
      if (value === 0) return 'aus';
      return (value < 0 ? 'Tiefpass ' : 'Hochpass ') + Math.abs(value) + ' %';
    }

    return signed(value, 1) + ' dB';
  }

  function pressButton(label, aria) {
    const button = el('button', 'skip', label);
    button.type = 'button';
    button.setAttribute('aria-pressed', 'false');
    if (aria) button.setAttribute('aria-label', aria);
    return button;
  }

  function deskControl(st, onTempo, options = {}) {
    const fixed = Boolean(options.fixed);
    const desk = el(fixed ? 'div' : 'details', 'desk' + (fixed ? ' fixed' : ''));
    const state = el('span', 'desk-state');

    if (!fixed) {
      const summary = el('summary');
      summary.append(el('span', null, 'Pult'), state);
      desk.append(summary);

      try {
        desk.open = localStorage.getItem('chiptune-desk') === 'open';
      } catch (error) {
        //
      }

      desk.addEventListener('toggle', () => {
        try {
          localStorage.setItem('chiptune-desk', desk.open ? 'open' : 'closed');
        } catch (error) {
          //
        }
      });
    }

    const body = el('div', 'desk-body');
    const refreshers = [];

    function refresh() {
      const active = st.volume !== 1 || st.rate !== 1 || st.fx.low || st.fx.mid || st.fx.high || st.fx.filter || st.fx.echo || st.kill.low || st.kill.mid || st.kill.high;
      state.textContent = active ? 'aktiv' : '';
      refreshers.forEach(fn => fn());
    }

    const tempoRow = el('div', 'pitch');
    const tempoSlider = el('input');
    tempoSlider.type = 'range';
    const range = options.range || 16;
    tempoSlider.min = String(-range);
    tempoSlider.max = String(range);
    tempoSlider.step = '0.1';
    tempoSlider.value = '0';
    tempoSlider.setAttribute('aria-label', 'Tempo anpassen, in Prozent');
    const tempoOut = el('b', null, '\u00b10.0 %');
    const tempoReset = el('button', 'skip', '1:1');
    tempoReset.type = 'button';
    tempoReset.setAttribute('aria-label', 'Tempo zurücksetzen');

    function applyTempo(user = true) {
      const value = Number(tempoSlider.value);
      st.rate = 1 + value / 100;
      tempoOut.textContent = signed(value, 1) + ' %';
      onTempo(user);
      refresh();
    }

    tempoSlider.addEventListener('input', () => applyTempo());
    tempoSlider.addEventListener('dblclick', () => {
      tempoSlider.value = '0';
      applyTempo();
    });
    tempoReset.addEventListener('click', () => {
      tempoSlider.value = '0';
      applyTempo();
    });
    tempoRow.append(el('span', 'pitch-name', 'Tempo'), tempoSlider, tempoOut, tempoReset);
    body.append(tempoRow);

    if (options.onVolume) {
      const volumeRow = el('div', 'pitch');
      const volumeSlider = el('input');
      volumeSlider.type = 'range';
      volumeSlider.min = '0';
      volumeSlider.max = '100';
      volumeSlider.step = '1';
      volumeSlider.value = String(Math.round(st.volume * 100));
      volumeSlider.setAttribute('aria-label', 'Lautstärke, in Prozent');
      const volumeOut = el('b', null, volumeSlider.value + ' %');
      const volumeReset = el('button', 'skip', '100');
      volumeReset.type = 'button';
      volumeReset.setAttribute('aria-label', 'Lautstärke auf 100 Prozent');

      const applyVolume = () => {
        volumeOut.textContent = volumeSlider.value + ' %';
        options.onVolume(Number(volumeSlider.value) / 100);
        refresh();
      };

      volumeSlider.addEventListener('input', applyVolume);
      volumeSlider.addEventListener('dblclick', () => {
        volumeSlider.value = '100';
        applyVolume();
      });
      volumeReset.addEventListener('click', () => {
        volumeSlider.value = '100';
        applyVolume();
      });
      volumeRow.append(el('span', 'pitch-name', 'Volume'), volumeSlider, volumeOut, volumeReset);
      body.append(volumeRow);
    }

    const mixer = el('div', 'mixer');
    const sliders = [];

    function showKnob(entry, slider, out) {
      const value = Number(slider.value);
      out.textContent = knobText(entry.key, value);
      st.fx[entry.key] = entry.key === 'filter' ? value / 100 : value;
      applyFx(st, false);
      refresh();
    }

    KNOBS.forEach(entry => {
      const slider = el('input');
      slider.type = 'range';
      slider.min = String(entry.min);
      slider.max = String(entry.max);
      slider.step = String(entry.step);
      slider.value = '0';
      slider.setAttribute('aria-label', entry.name + (entry.key === 'filter' ? ' (links Tiefpass, rechts Hochpass)' : ' in dB'));

      const out = el('b', null, knobText(entry.key, 0));
      slider.addEventListener('input', () => showKnob(entry, slider, out));
      slider.addEventListener('dblclick', () => {
        slider.value = '0';
        showKnob(entry, slider, out);
      });

      const group = el('div', 'knob');
      group.append(el('span', 'pitch-name', entry.name), slider, out);

      if (entry.kill) {
        const button = pressButton('Kill', entry.name + ' wegnehmen');
        button.addEventListener('click', () => {
          st.kill[entry.key] = !st.kill[entry.key];
          button.setAttribute('aria-pressed', String(st.kill[entry.key]));
          applyFx(st, false);
          refresh();
        });
        refreshers.push(() => button.setAttribute('aria-pressed', String(st.kill[entry.key])));
        group.append(button);
      }

      mixer.append(group);
      sliders.push([entry, slider, out]);
    });

    body.append(mixer);

    const effects = el('div', 'effects');

    function group(name) {
      const box = el('div', 'effect');
      box.append(el('span', 'pitch-name', name));
      effects.append(box);
      return box;
    }

    const loopBox = group('Loop');
    const loopButtons = [1, 2, 4].map(bars => {
      const button = pressButton(String(bars), 'Loop ' + bars + (bars === 1 ? ' Takt' : ' Takte'));
      button.addEventListener('click', () => {
        st.hold.loopBars = st.hold.loopBars === bars ? 0 : bars;
        st.hold.version++;
        loopButtons.forEach((other, index) => other.setAttribute('aria-pressed', String(st.hold.loopBars === [1, 2, 4][index])));
      });
      loopBox.append(button);
      return button;
    });

    const rollBox = group('Roll');
    [[1, '1/1'], [0.5, '1/2'], [0.25, '1/4']].forEach(([fraction, label]) => {
      const button = pressButton(label, 'Roll ' + label + ' Beat, solange gedrückt');
      const release = () => {
        if (st.hold.roll === fraction) {
          st.hold.roll = 0;
          st.hold.version++;
          button.setAttribute('aria-pressed', 'false');
        }
      };
      const press = () => {
        st.hold.roll = fraction;
        st.hold.version++;
        button.setAttribute('aria-pressed', 'true');
      };

      button.addEventListener('pointerdown', press);
      ['pointerup', 'pointerleave', 'pointercancel', 'blur'].forEach(name => button.addEventListener(name, release));
      button.addEventListener('keydown', event => {
        if ((event.key === ' ' || event.key === 'Enter') && !event.repeat) {
          event.preventDefault();
          press();
        }
      });
      button.addEventListener('keyup', event => {
        if (event.key === ' ' || event.key === 'Enter') release();
      });
      rollBox.append(button);
    });

    const echoBox = group('Echo');
    const echoButton = pressButton('Echo');
    echoButton.addEventListener('click', () => {
      st.fx.echo = !st.fx.echo;
      echoButton.setAttribute('aria-pressed', String(st.fx.echo));
      applyFx(st, false);
      refresh();
    });
    echoBox.append(echoButton);

    const resetButton = el('button', 'skip', 'Reset');
    resetButton.type = 'button';
    resetButton.setAttribute('aria-label', 'Pult zurücksetzen');
    resetButton.addEventListener('click', () => {
      sliders.forEach(([entry, slider, out]) => {
        slider.value = '0';
        out.textContent = knobText(entry.key, 0);
        st.fx[entry.key] = 0;
      });
      st.kill.low = st.kill.mid = st.kill.high = false;
      st.fx.echo = false;
      echoButton.setAttribute('aria-pressed', 'false');
      st.hold.loopBars = 0;
      st.hold.roll = 0;
      st.hold.version++;
      loopButtons.forEach(button => button.setAttribute('aria-pressed', 'false'));
      tempoSlider.value = '0';
      applyFx(st, false);
      applyTempo();
    });
    effects.append(resetButton);
    body.append(effects);
    desk.append(body);
    refresh();

    const lockNote = el('p', 'desk-lock', 'Das Pult wirkt nur auf die Chiptune-Version. Bei HQ und VÖ ist es ausgeschaltet und zurückgesetzt, ebenso Mute und Solo bei den Spuren.');
    lockNote.hidden = true;
    body.prepend(lockNote);

    desk.reset = () => resetButton.click();
    desk.lock = locked => {
      body.inert = locked;
      desk.classList.toggle('locked', locked);
      lockNote.hidden = !locked;

      if (locked) state.textContent = 'aus';
      else refresh();
    };

    desk.setTempo = (percentValue, exact) => {
      const clamped = Math.max(-range, Math.min(range, percentValue));
      tempoSlider.value = String(Math.round(clamped * 10) / 10);
      applyTempo(false);

      if (exact) {
        st.rate = 1 + clamped / 100;
        onTempo(false);
      }

      return clamped;
    };

    return desk;
  }

  function bpmText(bpm, st = S) {
    return String(Number((bpm * st.rate).toFixed(1)));
  }

  function makeLimiter() {
    const limiter = ctx.createDynamicsCompressor();
    limiter.threshold.value = -2;
    limiter.knee.value = 3;
    limiter.ratio.value = 20;
    limiter.attack.value = 0.003;
    limiter.release.value = 0.1;

    return limiter;
  }

  function makeNoise() {
    noise = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
    const samples = noise.getChannelData(0);
    for (let i = 0; i < samples.length; i++) samples[i] = Math.random() * 2 - 1;

    pulseCache.clear();
  }

  function buildChannel(st, out) {
    const input = ctx.createGain();
    input.gain.value = 0.8;

    const low = ctx.createBiquadFilter();
    low.type = 'lowshelf';
    low.frequency.value = 200;

    const mid = ctx.createBiquadFilter();
    mid.type = 'peaking';
    mid.frequency.value = 1000;
    mid.Q.value = 0.9;

    const high = ctx.createBiquadFilter();
    high.type = 'highshelf';
    high.frequency.value = 3500;

    const sweep = ctx.createBiquadFilter();

    const delay = ctx.createDelay(2);
    const feedback = ctx.createGain();
    feedback.gain.value = 0.4;
    const damp = ctx.createBiquadFilter();
    damp.type = 'lowpass';
    damp.frequency.value = 3200;
    const wet = ctx.createGain();
    wet.gain.value = 0;

    input.connect(delay);
    delay.connect(damp);
    damp.connect(feedback);
    feedback.connect(delay);
    delay.connect(wet);
    wet.connect(low);

    input.connect(low);
    low.connect(mid);
    mid.connect(high);
    high.connect(sweep);
    sweep.connect(out);

    st.nodes = { low, mid, high, sweep, delay, wet };
    applyFx(st, true);

    return input;
  }

  function initAudio() {
    ctx = new (window.AudioContext || window.webkitAudioContext)();
    makeNoise();

    const limiter = makeLimiter();
    limiter.connect(ctx.destination);
    songGain = ctx.createGain();
    songGain.gain.value = S.volume;
    songGain.connect(limiter);
    master = buildChannel(S, songGain);
  }

  function closeAudio() {
    if (ctx) ctx.close();
    ctx = null;
    songGain = null;
    S.nodes = null;
  }

  function applyEchoTime(st) {
    if (!ctx || !st.nodes) return;
    st.nodes.delay.delayTime.setTargetAtTime(Math.min(1.9, st.fx.beat * 0.75), ctx.currentTime, 0.05);
  }

  function holdRegion(st, latch, pointer, limit, stepsPerBar, stepsPerBeat) {
    const hold = st.hold;
    const length = hold.roll
      ? Math.max(1, Math.round(stepsPerBeat * hold.roll))
      : hold.loopBars * stepsPerBar;

    if (!length) return null;

    if (latch && latch.version === hold.version) return latch;

    const start = Math.floor(Math.min(pointer, limit - 1) / length) * length;

    return { version: hold.version, start, end: Math.min(start + length, limit) };
  }

  function applyFx(st, instant) {
    if (!ctx || !st.nodes) return;

    const now = ctx.currentTime;
    const set = (param, value) => {
      if (instant) param.value = value;
      else param.setTargetAtTime(value, now, 0.02);
    };

    set(st.nodes.low.gain, st.kill.low ? -40 : st.fx.low);
    set(st.nodes.mid.gain, st.kill.mid ? -40 : st.fx.mid);
    set(st.nodes.high.gain, st.kill.high ? -40 : st.fx.high);
    set(st.nodes.wet.gain, st.fx.echo ? 0.45 : 0);
    applyEchoTime(st);

    const amount = st.fx.filter;
    const sweep = st.nodes.sweep;

    sweep.type = amount > 0 ? 'highpass' : 'lowpass';
    set(sweep.frequency, amount > 0 ? 20 * Math.pow(250, amount) : 20000 * Math.pow(0.0125, -amount));
    set(sweep.Q, amount === 0 ? 0.7 : 2.5);
  }

  function makeBus(target) {
    const bus = ctx.createGain();
    bus.connect(target);
    return bus;
  }

  function pulseWave(width) {
    if (!pulseCache.has(width)) {
      const size = 64;
      const real = new Float32Array(size);
      const imag = new Float32Array(size);

      for (let k = 1; k < size; k++) {
        real[k] = Math.sin(2 * Math.PI * k * width) / (k * Math.PI);
        imag[k] = (1 - Math.cos(2 * Math.PI * k * width)) / (k * Math.PI);
      }

      pulseCache.set(width, ctx.createPeriodicWave(real, imag));
    }

    return pulseCache.get(width);
  }

  function tone(track, bus, event, when, stepSeconds) {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();

    if (track.instrument === 'pulse') osc.setPeriodicWave(pulseWave(track.pw));
    else osc.type = WAVES[track.instrument] || 'square';

    osc.frequency.value = event.f;

    const end = when + event.len * stepSeconds * track.gate;
    gain.gain.setValueAtTime(0, when);
    gain.gain.linearRampToValueAtTime(track.volume, when + 0.004);
    gain.gain.setValueAtTime(track.volume, Math.max(when + 0.004, end - 0.01));
    gain.gain.linearRampToValueAtTime(0, end);

    osc.connect(gain);
    gain.connect(bus);
    osc.start(when);
    osc.stop(end + 0.02);
  }

  function noiseHit(bus, when, { type, frequency, q = 0.7, decay, volume, bursts = [0] }) {
    const source = ctx.createBufferSource();
    source.buffer = noise;

    const filter = ctx.createBiquadFilter();
    filter.type = type;
    filter.frequency.value = frequency;
    filter.Q.value = q;

    const gain = ctx.createGain();
    gain.gain.setValueAtTime(0.0001, when);

    bursts.forEach((offset, i) => {
      const last = i === bursts.length - 1;
      gain.gain.setValueAtTime(volume, when + offset);
      gain.gain.exponentialRampToValueAtTime(last ? 0.001 : volume * 0.25, when + offset + (last ? decay : 0.009));
    });

    source.connect(filter);
    filter.connect(gain);
    gain.connect(bus);
    source.start(when);
    source.stop(when + bursts[bursts.length - 1] + decay + 0.02);
  }

  function kick(track, bus, when) {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();

    osc.type = 'sine';
    osc.frequency.setValueAtTime(160, when);
    osc.frequency.exponentialRampToValueAtTime(40, when + 0.12);
    gain.gain.setValueAtTime(track.volume * 1.6, when);
    gain.gain.exponentialRampToValueAtTime(0.001, when + 0.16);

    osc.connect(gain);
    gain.connect(bus);
    osc.start(when);
    osc.stop(when + 0.18);
  }

  function drum(track, bus, event, when) {
    const volume = track.volume;

    switch (event.d) {
      case 'k':
        kick(track, bus, when);
        break;
      case 'h':
        noiseHit(bus, when, { type: 'highpass', frequency: 7000, decay: 0.04, volume });
        break;
      case 'o':
        noiseHit(bus, when, { type: 'highpass', frequency: 6000, decay: 0.25, volume });
        break;
      case 's':
        noiseHit(bus, when, { type: 'highpass', frequency: 1500, decay: 0.13, volume });
        break;
      case 'c':
        noiseHit(bus, when, { type: 'bandpass', frequency: 1500, q: 0.8, decay: 0.12, volume, bursts: [0, 0.011, 0.022, 0.033] });
        break;
    }
  }


  function projectDeck(project, st, host) {
    const mixing = Boolean(host.mix);
    const tracks = project.tracks;
    const bars = project.steps / project.stepsPerBar;

    let buses = [];
    let timer = null;
    let frameId = 0;
    let nextTime = 0;
    let nextStep = 0;
    let ended = false;
    let marks = [];
    let latch = null;
    let playing = false;
    let starting = false;
    let resumeStep = 0;
    let channel = null;

    const muted = tracks.map(() => false);
    const soloed = tracks.map(() => false);
    const rows = [];

    const playButton = el('button', 'play', 'Play');
    playButton.type = 'button';
    playButton.setAttribute('aria-keyshortcuts', 'Space');

    const barOut = el('b', null, '1');
    const beatOut = el('b', null, '1');
    const position = el('div', 'position');
    position.setAttribute('role', 'timer');
    position.append(document.createTextNode('Bar '), barOut, document.createTextNode(' Beat '), beatOut);

    const bpmOut = el('b', null, bpmText(project.bpm, st));
    const tempo = el('span', 'tempo');
    tempo.append(bpmOut, document.createTextNode(' BPM'));

    const transport = el('div', 'transport');
    transport.append(playButton, position, tempo);

    let leds = null;
    let ledIndex = -1;
    let beatIndex = -1;

    if (mixing) {
      const strip = el('div', 'leds');
      strip.setAttribute('aria-hidden', 'true');
      leds = Array.from({ length: 16 }, (_, index) => {
        const node = el('i');
        node.style.setProperty('--h', String(Math.round((index / 16) * 300)));
        if (index % 4 === 0) node.classList.add('beat');
        strip.append(node);
        return node;
      });
      transport.append(strip);
    }

    function light(step) {
      if (!leds) return;

      const index = Math.floor(((step % project.stepsPerBar) / project.stepsPerBar) * 16);

      if (index !== ledIndex) {
        leds.forEach((node, i) => {
          node.classList.toggle('on', i === index);
          node.classList.toggle('tail', i === (index + 15) % 16 || i === (index + 14) % 16);
        });
        ledIndex = index;
      }

      const beat = Math.floor(step / project.stepsPerBeat);

      if (beat !== beatIndex) {
        beatIndex = beat;
        if (playing && host.beat) host.beat(beat % Math.max(1, Math.round(project.stepsPerBar / project.stepsPerBeat)));
      }
    }

    function lightsOff() {
      if (!leds) return;

      leds.forEach(node => node.classList.remove('on', 'tail'));
      ledIndex = -1;
      beatIndex = -1;
      if (host.beat) host.beat(-1);
    }

    const desk = deskControl(st, user => {
      bpmOut.textContent = bpmText(project.bpm, st);
      if (user && host.tempoChanged) host.tempoChanged(api);
    }, mixing ? { fixed: true, range: 20 } : { onVolume: setSongVolume });

    const byStep = Array.from({ length: project.steps }, () => []);
    tracks.forEach((track, index) => track.events.forEach(event => byStep[event.t] && byStep[event.t].push([index, event])));

    const arrangement = el('div', 'arrangement');
    arrangement.style.setProperty('--bars', String(bars));
    arrangement.style.setProperty('--beats', String(project.steps / project.stepsPerBeat));

    function gridSvg() {
      const svg = svgNode('svg', { class: 'grid', viewBox: '0 0 ' + project.steps + ' 1', preserveAspectRatio: 'none', 'shape-rendering': 'crispEdges', 'aria-hidden': 'true' });
      const beats = Math.ceil(project.steps / project.stepsPerBeat);
      let tint = '';
      let lines = '';

      for (let beat = 1; beat < beats; beat += 2) {
        tint += 'M' + beat * project.stepsPerBeat + ' 0h' + project.stepsPerBeat + 'v1h-' + project.stepsPerBeat + 'z';
      }

      for (let bar = 1; bar * project.stepsPerBar < project.steps; bar++) {
        lines += 'M' + bar * project.stepsPerBar + ' 0V1';
      }

      svg.append(svgNode('path', { class: 'beat', d: tint }), svgNode('path', { class: 'bar', d: lines }));

      return svg;
    }

    arrangement.append(el('div', 'corner'));
    const ruler = el('div', 'ruler');
    ruler.append(gridSvg());
    ruler.setAttribute('aria-hidden', 'true');

    if (mixing) {
      ruler.classList.add('seekable');
      ruler.title = 'Klick springt zu diesem Takt';
      ruler.addEventListener('click', event => {
        const box = ruler.getBoundingClientRect();
        const bar = Math.floor(((event.clientX - box.left) / box.width) * bars);
        const within = playing ? currentStep() % project.stepsPerBar : 0;
        seek(Math.max(0, Math.min(bar, Math.floor(bars) - 1)) * project.stepsPerBar + within);
      });
    }

    const narrow = typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 44rem)').matches;
    const labelStep = (narrow && bars > 8) || bars > 24 ? 4 : 1;

    for (let bar = 0; bar < Math.floor(bars); bar++) {
      if (bar % labelStep !== 0) {
        continue;
      }

      const mark = el('span', null, String(bar + 1));
      mark.style.left = percent(bar / bars);
      ruler.append(mark);
    }
    arrangement.append(ruler);

    function showTrackNotes(index) {
      const details = document.getElementById('track-' + (index + 1));

      if (!details) {
        return;
      }

      if (details.open) {
        details.open = false;
        return;
      }

      details.open = true;
      details.scrollIntoView({ block: 'center' });
      const summary = details.querySelector('summary');

      if (summary) {
        summary.focus({ preventScroll: true });
      }
    }

    let currentGroup = null;

    tracks.forEach((track, index) => {
      if (track.group && !mixing && (!currentGroup || currentGroup.title !== track.group.title || currentGroup.href !== track.group.href)) {
        currentGroup = track.group;
        const header = el('div', 'group-head');
        const link = el('a', null, track.group.title);
        link.href = track.group.href;
        header.append(link);

        if (Math.abs(track.group.bpm - project.bpm) >= 0.05) {
          header.append(el('span', 'group-bpm', formatBpm(track.group.bpm) + ' -> ' + formatBpm(project.bpm) + ' BPM'));
        }

        arrangement.append(header);
      }

      const group = el('div', 'track t' + (index % 8));
      group.setAttribute('role', 'group');
      group.setAttribute('aria-label', 'Track ' + track.name);

      const head = el('div', 'head');
      const label = el('div', 'label-box');
      let nameElement;

      if (track.notes && !mixing) {
        nameElement = el('button', 'name has-notes', track.name);
        nameElement.type = 'button';
        nameElement.title = 'Beschreibung anzeigen';
        nameElement.addEventListener('click', () => showTrackNotes(index));
      } else {
        nameElement = el('span', 'name', track.name);
      }

      label.append(nameElement, el('span', 'instrument', instrumentLabel(track)));

      const refs = track.refs || [];

      if (refs.length > 0 && !track.group && !mixing) {
        const titles = [...new Set(refs.map(ref => ref.title))];
        const sources = el('span', 'ref');
        const link = el('a', null, '-> ' + (titles.length === 1 ? titles[0] : titles.length + ' Quellen'));
        link.href = refs[0].href;
        link.title = 'Aus ' + titles.join(', ');
        sources.append(link);
        label.append(sources);
      }

      const buttons = el('div', 'buttons');
      const muteButton = el('button', 'mute', 'M');
      muteButton.type = 'button';
      muteButton.setAttribute('aria-label', 'Mute ' + track.name);
      muteButton.addEventListener('click', () => toggle(muted, index));

      const soloButton = el('button', 'solo', 'S');
      soloButton.type = 'button';
      soloButton.setAttribute('aria-label', 'Solo ' + track.name);
      soloButton.addEventListener('click', () => toggle(soloed, index));

      buttons.append(muteButton, soloButton);
      head.append(label, buttons);

      const lane = el('div', 'lane');
      lane.setAttribute('aria-hidden', 'true');
      lane.append(gridSvg());
      layoutClips(track, lane);
      layoutNotes(track, lane);

      group.append(head, lane);
      arrangement.append(group);
      rows.push({ group, muteButton, soloButton });
    });

    const layer = el('div', 'playhead-layer');
    layer.setAttribute('aria-hidden', 'true');
    const playhead = el('div', 'playhead');
    playhead.hidden = true;
    layer.append(playhead);
    arrangement.append(layer);

    applyMix();

    function formatBpm(value) {
      return String(Math.round(value * 10) / 10);
    }

    function layoutClips(track, lane) {
      (track.refs || []).forEach(ref => {
        const band = el('span', 'clip');
        band.title = ref.title + ' | ' + ref.track;
        band.style.left = percent(ref.start / project.steps);
        band.style.width = percent(ref.len / project.steps);
        band.append(el('span', null, ref.title));
        lane.append(band);
      });
    }

    function layoutNotes(track, lane) {
      const height = 56;
      const pad = 4;
      const inner = height - pad * 2;
      const noteHeight = 8;
      const svg = svgNode('svg', { class: 'roll', viewBox: '0 0 ' + project.steps + ' ' + height, preserveAspectRatio: 'none', 'shape-rendering': 'crispEdges' });

      function place(className, event, top, size) {
        svg.append(svgNode('rect', { class: className, x: event.t, y: top, width: event.len, height: size }));
      }

      lane.append(svg);

      if (track.instrument === 'drums') {
        const used = DRUM_ORDER.filter(kind => track.events.some(event => event.d === kind));
        const part = inner / Math.max(used.length, 1);

        track.events.forEach(event => {
          place('note drum-' + event.d, event, pad + used.indexOf(event.d) * part, Math.max(part - (used.length > 1 ? 1 : 0), 2));
        });

        return;
      }

      const midis = track.events.map(event => 69 + 12 * Math.log2(event.f / 440));
      const low = Math.min(...midis);
      const span = Math.max(...midis) - low;

      track.events.forEach((event, i) => {
        const level = span > 0 ? (midis[i] - low) / span : 0.5;
        place('note', event, pad + (1 - level) * (inner - noteHeight), noteHeight);
      });
    }

    let tracksLocked = false;

    function toggle(list, index) {
      if (tracksLocked) return;

      list[index] = !list[index];
      applyMix();
    }

    function lockTracks(locked) {
      tracksLocked = locked;

      if (locked) {
        muted.fill(false);
        soloed.fill(false);
        applyMix();
      }

      rows.forEach(row => {
        row.muteButton.disabled = locked;
        row.soloButton.disabled = locked;
      });
    }

    function applyMix() {
      const anySolo = soloed.some(Boolean);

      rows.forEach((row, index) => {
        const audible = anySolo ? soloed[index] : !muted[index];
        row.group.classList.toggle('silent', !audible);
        row.muteButton.setAttribute('aria-pressed', String(muted[index]));
        row.soloButton.setAttribute('aria-pressed', String(soloed[index]));

        if (ctx && buses[index]) {
          buses[index].gain.setTargetAtTime(audible ? 1 : 0, ctx.currentTime, 0.01);
        }
      });
    }

    function showStep(step) {
      light(step);
      playhead.style.left = percent(step / project.steps);
      barOut.textContent = String(Math.floor(step / project.stepsPerBar) + 1);
      beatOut.textContent = String(Math.floor((step % project.stepsPerBar) / project.stepsPerBeat) + 1);
    }

    function renderButton() {
      playButton.textContent = playing ? (mixing ? 'Pause' : 'Stop') : 'Play';
      playButton.classList.toggle('on', playing);
      playhead.hidden = !playing && !(mixing && resumeStep > 0);

      if (!playing && !(mixing && resumeStep > 0)) {
        barOut.textContent = '1';
        beatOut.textContent = '1';
      }
    }

    function currentStep() {
      if (!ctx || marks.length === 0) return resumeStep;

      const now = ctx.currentTime;
      let mark = marks[0];

      for (const candidate of marks) {
        if (candidate.time <= now) mark = candidate;
      }

      return Math.max(0, Math.min(mark.step + Math.max(0, now - mark.time) / mark.dur, project.steps - 1e-6));
    }

    function frame() {
      if (!playing || !ctx) return;

      const now = ctx.currentTime;

      while (marks.length > 1 && marks[1].time <= now) marks.shift();

      const mark = marks[0];

      if (mark && now >= mark.time) {
        showStep(Math.min(mark.step + (now - mark.time) / mark.dur, project.steps - 1e-6));
      }

      frameId = requestAnimationFrame(frame);
    }

    function tick() {
      if (!ctx) return;

      while (!ended && nextTime - ctx.currentTime < (mixing ? MIX_LOOKAHEAD : LOOKAHEAD)) {
        latch = holdRegion(st, latch, nextStep, project.steps, project.stepsPerBar, project.stepsPerBeat);

        if (latch && nextStep >= latch.end) nextStep = latch.start;

        if (nextStep >= project.steps) {
          if (!project.loop) {
            ended = true;
            break;
          }

          nextStep = 0;
        }

        const dur = project.stepSeconds / speed(st);
        st.fx.beat = dur * project.stepsPerBeat;

        byStep[nextStep].forEach(([index, event]) => {
          const voice = tracks[index].instrument === 'drums' ? drum : tone;
          voice(tracks[index], buses[index], event, nextTime, dur);
        });

        marks.push({ time: nextTime, dur, step: nextStep });
        nextTime += dur;
        nextStep++;
      }

      applyEchoTime(st);

      if (ended && ctx.currentTime > nextTime) stop(false);
    }

    async function play(when) {
      if (playing || starting) return;
      starting = true;

      channel = await host.open();
      const target = channel;
      buses = tracks.map(() => makeBus(target));

      starting = false;
      applyMix();

      marks = [];
      latch = null;
      ended = false;
      nextStep = mixing ? Math.floor(resumeStep) : 0;
      nextTime = typeof when === 'number' && when > ctx.currentTime ? when : ctx.currentTime + 0.1;
      tick();

      timer = setInterval(tick, mixing ? 40 : 100);
      playing = true;
      renderButton();
      if (host.changed) host.changed();
      frameId = requestAnimationFrame(frame);

      if (mixing && api.auto && typeof when !== 'number' && host.autoStart) host.autoStart(api);
    }

    function stop(keep) {
      if (mixing) resumeStep = keep ? currentStep() : 0;

      clearInterval(timer);
      cancelAnimationFrame(frameId);

      if (mixing) {
        buses.forEach(bus => bus.disconnect());
      } else {
        closeAudio();
      }

      buses = [];
      marks = [];
      playing = false;
      renderButton();
      if (host.changed) host.changed();

      if (mixing) {
        lightsOff();
        showStep(resumeStep);
        lightsOff();
      }
    }

    function toggleTransport() {
      if (api.override && api.override()) return;

      if (playing) stop(true);
      else play();
    }

    playButton.addEventListener('click', toggleTransport);

    function follow(step) {
      if (step === null) {
        playhead.hidden = true;
        barOut.textContent = '1';
        beatOut.textContent = '1';
        return;
      }

      playhead.hidden = false;
      showStep(step);
    }

    function external(on) {
      playButton.textContent = on ? 'Stop' : 'Play';
      playButton.classList.toggle('on', on);
    }

    function rewire() {
      buses.forEach(bus => bus.disconnect());
      buses = tracks.map(() => makeBus(channel));
      applyMix();
      marks = [];
      latch = null;
      ended = false;
    }

    function seek(step) {
      const target = Math.max(0, Math.min(Math.floor(step), project.steps - 1));

      if (!playing) {
        resumeStep = target;
        renderButton();
        showStep(target);
        return;
      }

      rewire();
      nextStep = target;
      nextTime = ctx.currentTime + 0.03;
      tick();
    }

    function seekBars(delta) {
      seek(currentStep() + delta * project.stepsPerBar);
    }

    function positionAt(time) {
      if (!playing || !ctx || marks.length === 0) return null;

      let mark = marks[0];

      for (const candidate of marks) {
        if (candidate.time <= time) mark = candidate;
        else break;
      }

      return mark.step + (time - mark.time) / mark.dur;
    }

    function align(other) {
      if (!playing || !ctx) return false;

      const t0 = ctx.currentTime + 0.06;
      const theirs = other.positionAt(t0);
      const mine = positionAt(t0);

      if (theirs === null || mine === null) return false;

      const theirBar = other.project.stepsPerBar;
      const bar = project.stepsPerBar;
      const phase = (((theirs % theirBar) + theirBar) % theirBar) / theirBar;
      const base = Math.floor(mine / bar);
      let best = null;

      [base - 1, base, base + 1].forEach(index => {
        const candidate = (index + phase) * bar;

        if (candidate < 0 || candidate >= project.steps - 1) return;
        if (best === null || Math.abs(candidate - mine) < Math.abs(best - mine)) best = candidate;
      });

      if (best === null) return false;

      rewire();
      const first = Math.ceil(best);
      nextStep = first;
      nextTime = t0 + (first - best) * (project.stepSeconds / speed(st));
      tick();

      return true;
    }

    const api = { transport, desk, arrangement, bpmOut, project, st, toggleTransport, stop, play, seek, seekBars, positionAt, align, currentStep, follow, external, lockTracks, isPlaying: () => playing, auto: false };

    if (mixing) {
      const controls = el('div', 'deck-controls');
      const button = (label, aria, onClick) => {
        const node = el('button', 'skip', label);
        node.type = 'button';
        node.setAttribute('aria-label', aria);
        if (onClick) node.addEventListener('click', onClick);
        return node;
      };
      const row = (...nodes) => {
        const box = el('div', 'deck-row');
        box.append(...nodes);
        return box;
      };

      const stepsPerBeat = project.stepsPerBeat;
      const cues = { main: 0, hot: [null, null, null, null] };
      const storeKey = host.key ? 'chiptune-cues:' + host.key : '';

      try {
        const saved = storeKey ? JSON.parse(localStorage.getItem(storeKey) || 'null') : null;

        if (saved && Number.isFinite(saved.main) && Array.isArray(saved.hot)) {
          cues.main = Math.max(0, Math.min(saved.main, project.steps - 1));
          cues.hot = [0, 1, 2, 3].map(index => (Number.isFinite(saved.hot[index]) && saved.hot[index] < project.steps ? saved.hot[index] : null));
        }
      } catch (error) {
        //
      }

      function saveCues() {
        try {
          if (storeKey) localStorage.setItem(storeKey, JSON.stringify(cues));
        } catch (error) {
          //
        }
      }

      const marksLayer = [];

      function snap() {
        const step = Math.round(currentStep() / stepsPerBeat) * stepsPerBeat;

        return Math.max(0, Math.min(step, project.steps - 1));
      }

      function where(step) {
        return 'Bar ' + (Math.floor(step / project.stepsPerBar) + 1) + ' Beat ' + (Math.floor((step % project.stepsPerBar) / stepsPerBeat) + 1);
      }

      const hotButtons = [0, 1, 2, 3].map(index => {
        const node = button(String(index + 1), 'Hot-Cue ' + (index + 1), () => {
          if (cues.hot[index] === null) cues.hot[index] = snap();
          else seek(cues.hot[index]);
          saveCues();
          drawCues();
        });
        node.classList.add('hot');
        node.style.setProperty('--h', String([0, 110, 200, 290][index]));
        return node;
      });

      function drawCues() {
        marksLayer.splice(0).forEach(node => node.remove());

        const place = (step, className, label, hue) => {
          const node = el('i', 'cue-mark ' + className, label);
          node.style.left = percent(step / project.steps);
          if (hue !== undefined) node.style.setProperty('--h', String(hue));
          ruler.append(node);
          marksLayer.push(node);
        };

        if (cues.main > 0) place(cues.main, 'main', '▾');
        cues.hot.forEach((step, index) => {
          if (step !== null) place(step, 'hot', String(index + 1), [0, 110, 200, 290][index]);
        });

        hotButtons.forEach((node, index) => {
          const set = cues.hot[index] !== null;
          node.setAttribute('aria-pressed', String(set));
          node.title = set ? 'Springt zu ' + where(cues.hot[index]) + ' (Löschen: Cues löschen)' : 'Setzt einen Cue an der aktuellen Position';
        });
      }

      const cueButton = button('Cue', 'Zum Cue-Punkt springen', () => seek(cues.main));
      const setCue = button('Cue setzen', 'Cue-Punkt an der aktuellen Position setzen', () => {
        cues.main = snap();
        saveCues();
        drawCues();
      });
      const clearCues = button('×', 'Alle Cue-Punkte dieses Songs löschen', () => {
        cues.main = 0;
        cues.hot = [null, null, null, null];
        saveCues();
        drawCues();
      });
      clearCues.title = 'Cues löschen';

      const bar = (label, delta, aria) => button(label, aria, () => seekBars(delta));

      const nudge = (label, direction, aria) => {
        const node = button(label, aria);
        const show = () => {
          bpmOut.textContent = String(Number((project.bpm * speed(st)).toFixed(1)));
        };
        const press = () => {
          st.nudge = direction * 0.08;
          node.setAttribute('aria-pressed', 'true');
          show();
        };
        const release = () => {
          st.nudge = 0;
          node.setAttribute('aria-pressed', 'false');
          show();
        };
        node.addEventListener('pointerdown', press);
        ['pointerup', 'pointerleave', 'pointercancel', 'blur'].forEach(name => node.addEventListener(name, release));
        node.addEventListener('keydown', event => {
          if ((event.key === ' ' || event.key === 'Enter') && !event.repeat) {
            event.preventDefault();
            press();
          }
        });
        node.addEventListener('keyup', event => {
          if (event.key === ' ' || event.key === 'Enter') release();
        });
        return node;
      };

      const sync = button('Sync', 'Tempo einmal an das andere Deck angleichen', () => host.sync(api, false));
      const auto = button('Auto', 'Auto-Sync: Tempo folgen und im Takt des anderen Decks bleiben', () => {
        api.auto = !api.auto;
        auto.setAttribute('aria-pressed', String(api.auto));

        if (api.auto) host.autoStart(api);
      });
      auto.setAttribute('aria-pressed', 'false');
      auto.title = 'Tempo folgt dem anderen Deck, der Start landet im selben Takt';
      api.setAuto = value => {
        api.auto = Boolean(value);
        auto.setAttribute('aria-pressed', String(api.auto));

        if (api.auto) host.autoStart(api);
      };

      controls.append(
        row(cueButton, setCue, ...hotButtons, clearCues),
        row(bar('‹ Takt', -1, 'Einen Takt zurück'), bar('Takt ›', 1, 'Einen Takt vor'), nudge('−', -1, 'Nudge langsamer, solange gedrückt'), nudge('+', 1, 'Nudge schneller, solange gedrückt'), sync, auto),
      );

      drawCues();
      api.controls = controls;
      api.bpm = () => project.bpm * st.rate;
      api.barSeconds = () => (project.stepsPerBar * project.stepSeconds) / speed(st);
      api.playing = () => playing;
    } else {
      document.addEventListener('keydown', event => {
        if (event.ctrlKey || event.metaKey || event.altKey) return;

        const onBody = event.target === document.body;

        if (!onBody && !(event.target instanceof HTMLButtonElement)) return;

        if (event.code === 'Space' && onBody) {
          event.preventDefault();
          toggleTransport();
          return;
        }

        const number = Number(event.key);

        if (Number.isInteger(number) && number >= 1 && number <= tracks.length) {
          toggle(muted, number - 1);
        }
      });
    }

    return api;
  }

  const MIDI_PROGRAMS = { square: 80, pulse: 80, saw: 81, triangle: 73, sine: 79 };
  const MIDI_DRUMS = { k: 36, s: 38, c: 39, h: 42, o: 46 };
  const MIDI_TICKS = 480;

  function midiNote(frequency) {
    return Math.max(0, Math.min(127, Math.round(69 + 12 * Math.log2(frequency / 440))));
  }

  function varLength(value) {
    const bytes = [value & 0x7f];

    for (let rest = value >>> 7; rest > 0; rest >>>= 7) bytes.unshift((rest & 0x7f) | 0x80);

    return bytes;
  }

  function midiText(type, text) {
    const bytes = Array.from(new TextEncoder().encode(text)).slice(0, 200);

    return [0xff, type, ...varLength(bytes.length), ...bytes];
  }

  function midiChunk(name, body) {
    const size = body.length;

    return [
      ...Array.from(name, letter => letter.charCodeAt(0)),
      (size >>> 24) & 255, (size >>> 16) & 255, (size >>> 8) & 255, size & 255,
      ...body,
    ];
  }

  function midiFile(project) {
    const perStep = MIDI_TICKS / project.stepsPerBeat;
    const microseconds = Math.round(60000000 / project.bpm);
    const total = Math.round(project.steps * perStep);

    const head = [
      ...varLength(0), ...midiText(0x03, project.title),
      ...varLength(0), ...midiText(0x01, `Quelle: ${location.origin}${location.pathname}`),
      ...varLength(0), 0xff, 0x51, 3, (microseconds >> 16) & 255, (microseconds >> 8) & 255, microseconds & 255,
      ...varLength(0), 0xff, 0x58, 4, 4, 2, 24, 8,
      ...varLength(total), 0xff, 0x2f, 0,
    ];
    const chunks = [midiChunk('MTrk', head)];
    let melodic = 0;

    for (const track of project.tracks) {
      const drums = track.instrument === 'drums';
      let channel = 9;

      if (!drums) {
        channel = melodic % 15;
        if (channel >= 9) channel += 1;
        melodic += 1;
      }

      const velocity = Math.max(1, Math.min(127, Math.round(60 + (track.volume / 0.34) * 50)));
      const messages = [];

      for (const event of track.events) {
        const note = drums ? MIDI_DRUMS[event.d] : midiNote(event.f);

        if (note === undefined) continue;

        const start = Math.round(event.t * perStep);
        const length = drums ? Math.round(perStep) : Math.max(1, Math.round(event.len * track.gate * perStep));

        messages.push({ tick: start, order: 1, bytes: [0x90 | channel, note, velocity] });
        messages.push({ tick: start + length, order: 0, bytes: [0x80 | channel, note, 0] });
      }

      messages.sort((a, b) => a.tick - b.tick || a.order - b.order);

      const body = [...varLength(0), ...midiText(0x03, track.name)];

      if (!drums) body.push(...varLength(0), 0xc0 | channel, MIDI_PROGRAMS[track.instrument] ?? 80);

      let last = 0;

      for (const message of messages) {
        body.push(...varLength(message.tick - last), ...message.bytes);
        last = message.tick;
      }

      body.push(...varLength(Math.max(0, total - last)), 0xff, 0x2f, 0);
      chunks.push(midiChunk('MTrk', body));
    }

    const header = midiChunk('MThd', [0, 1, (chunks.length >> 8) & 255, chunks.length & 255, MIDI_TICKS >> 8, MIDI_TICKS & 255]);

    return new Uint8Array([...header, ...chunks.flat()]);
  }

  function midiLink(project) {
    const button = el('button', 'midi-link', 'MIDI');
    button.type = 'button';
    button.title = 'Song als MIDI-Datei speichern (Noten und Schlagzeug, ohne Klang)';
    button.addEventListener('click', () => {
      const name = (project.title || 'song').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'song';
      const url = URL.createObjectURL(new Blob([midiFile(project)], { type: 'audio/midi' }));
      const link = document.createElement('a');

      link.href = url;
      link.download = `${name}.mid`;
      document.body.append(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });

    const strudel = el('button', 'midi-link', 'Strudel');
    strudel.type = 'button';
    strudel.title = 'Song als Text in Strudel öffnen (neuer Tab auf strudel.cc, erst beim Klick wird dorthin verbunden)';
    strudel.addEventListener('click', () => {
      window.open(strudelUrl(strudelCode(project)), '_blank', 'noopener,noreferrer');
    });

    const line = el('p', 'midi');
    line.append(strudel, button);

    return line;
  }

  const STRUDEL_DRUMS = { k: 'bd', s: 'sd', c: 'cp', h: 'hh', o: 'oh' };
  const STRUDEL_VOICES = { square: 'square', pulse: 'square', saw: 'sawtooth', triangle: 'triangle', sine: 'sine' };
  const STRUDEL_NAMES = ['c', 'c#', 'd', 'd#', 'e', 'f', 'f#', 'g', 'g#', 'a', 'a#', 'b'];

  function strudelNote(frequency) {
    const midi = midiNote(frequency);

    return STRUDEL_NAMES[midi % 12] + (Math.floor(midi / 12) - 1);
  }

  function asciiText(text) {
    return String(text).replace(/[äÄ]/g, 'ae').replace(/[öÖ]/g, 'oe').replace(/[üÜ]/g, 'ue').replace(/ß/g, 'ss').replace(/[^\x20-\x7e]/g, '').replace(/\s+/g, ' ').trim();
  }

  function strudelBar(row, compact) {
    if (row.every(cell => cell === '~')) return '~';

    if (compact) {
      for (let size = 1; size < row.length; size++) {
        if (row.length % size !== 0 || !row.every((cell, index) => cell === row[index % size])) continue;

        const block = row.slice(0, size);

        return (size === 1 ? block[0] : '[' + block.join(' ') + ']') + '*' + (row.length / size);
      }
    }

    return '[' + row.join(' ') + ']';
  }

  function strudelBars(rows, compact) {
    const lines = [];

    for (let index = 0; index < rows.length;) {
      let end = index + 1;

      while (compact && end < rows.length && rows[end] === rows[index]) end++;

      const count = end - index;
      const text = count === 1 ? rows[index] : (rows[index].includes('*') ? '[' + rows[index] + ']' : rows[index]) + '!' + count;

      lines.push(text);
      index = end;
    }

    return lines;
  }

  function strudelCode(project, compact = true) {
    const barSteps = project.stepsPerBeat * 4;
    const bars = Math.ceil(project.steps / barSteps);
    const tracks = [];

    for (const track of project.tracks) {
      const drums = track.instrument === 'drums';
      const cells = new Array(bars * barSteps).fill('~');

      for (const event of track.events) {
        const name = drums ? STRUDEL_DRUMS[event.d] : strudelNote(event.f);

        if (!name || event.t >= cells.length) continue;

        cells[event.t] = name;

        const end = Math.min(event.t + (drums ? 1 : event.len), (Math.floor(event.t / barSteps) + 1) * barSteps);

        for (let step = event.t + 1; step < end; step++) cells[step] = '_';
      }

      const rows = [];

      for (let bar = 0; bar < bars; bar++) rows.push(strudelBar(cells.slice(bar * barSteps, (bar + 1) * barSteps), compact));

      const lines = strudelBars(rows, compact);
      const gain = Math.min(1, Math.round(track.volume * 250) / 100);
      const label = asciiText(track.name) || 'track';
      const body = '`\n    <' + lines.join('\n     ') + '>\n  `';
      const voice = drums
        ? `s(${body}).gain(${gain})`
        : `note(${body}).s("${STRUDEL_VOICES[track.instrument] || 'square'}").clip(${Math.round(track.gate * 100) / 100}).gain(${gain})`;

      tracks.push(`  // ${label}\n  ${voice}`);
    }

    const tempo = Number.isInteger(project.bpm) ? String(project.bpm) : String(Math.round(project.bpm * 100) / 100);

    return [
      `// ${asciiText(project.title) || 'Song'} (aus chiptune.kchaot.de, ein Takt = ein Zyklus)`,
      `setcps(${tempo}/60/4)`,
      '',
      'stack(',
      tracks.join(',\n'),
      ')',
      '',
    ].join('\n');
  }

  function strudelUrl(code) {
    const bytes = new TextEncoder().encode(code);
    let binary = '';

    for (let i = 0; i < bytes.length; i += 8192) binary += String.fromCharCode(...bytes.subarray(i, i + 8192));

    return 'https://strudel.cc/#' + encodeURIComponent(btoa(binary));
  }

  function studioPanel(deck, project) {
    const media = project.media || {};
    const secure = value => {
      try {
        return typeof value === 'string' && new URL(value).protocol === 'https:' ? value : null;
      } catch (error) {
        return null;
      }
    };
    const hqUrl = secure(media.hq);
    const releaseUrl = secure(media.release);
    const own = media.releaseOwn === true && releaseUrl !== null;
    const coverUrl = own ? secure(media.cover) : null;

    if (!hqUrl && !releaseUrl) return { bar: null, note: null, card: null };

    const HASHES = { hq: 'hq', release: 'vo' };
    const versions = [{ id: 'chip', label: 'Chiptune', note: 'Die Version aus dem Raster, vom Player erzeugt.' }];

    if (hqUrl) {
      versions.push({ id: 'hq', label: 'HQ', url: hqUrl, sync: true, offset: Number(media.hqOffset) || 0, note: 'Dieselben Noten, in einer DAW gerendert. Der Abspielkopf läuft mit.' });
    }

    if (own) {
      versions.push({ id: 'release', label: 'VÖ', url: releaseUrl, sync: false, offset: 0, note: 'Die veröffentlichte Version, frei produziert. Sie läuft nicht im Takt des Rasters.' });
    }

    const bar = el('div', 'versions-bar');
    const note = el('p', 'studio-note');
    note.hidden = true;
    note.setAttribute('aria-live', 'polite');
    const buttons = new Map();
    const audios = new Map();
    const info = media.info && typeof media.info === 'object' ? media.info : {};
    let card = null;
    let seekBar = null;
    let timeOut = null;

    const clock = seconds => {
      if (!Number.isFinite(seconds) || seconds < 0) return '–:––';

      return Math.floor(seconds / 60) + ':' + String(Math.floor(seconds % 60)).padStart(2, '0');
    };

    const dateText = value => {
      const match = /^(\d{4})(?:-(\d{2})(?:-(\d{2}))?)?$/.exec(typeof value === 'string' ? value : '');

      if (!match) return '';
      if (!match[2]) return match[1];

      try {
        const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3] || 1)));
        const options = match[3] ? { day: 'numeric', month: 'long', year: 'numeric' } : { month: 'long', year: 'numeric' };

        return date.toLocaleDateString('de-DE', { ...options, timeZone: 'UTC' });
      } catch (error) {
        return match[0];
      }
    };

    if (own) {
      card = el('section', 'release-card' + (coverUrl ? '' : ' no-cover'));
      card.hidden = true;
      card.setAttribute('aria-label', 'Veröffentlichung');

      if (coverUrl) {
        const cover = el('img', 'cover');
        cover.alt = 'Cover';
        cover.width = 224;
        cover.height = 224;
        cover.decoding = 'async';
        cover.referrerPolicy = 'no-referrer';
        cover.addEventListener('error', () => {
          cover.remove();
          card.classList.add('no-cover');
        });
        cover.dataset.src = coverUrl;
        card.append(cover);
      }

      const body = el('div', 'release-body');
      const kicker = ['Veröffentlichung', typeof info.label === 'string' ? info.label : ''].filter(Boolean).join(' | ');
      const head = el('div', 'release-head');
      const share = el('button', 'release-share', 'Link kopieren');
      share.type = 'button';
      share.title = 'Direktlink zu dieser Veröffentlichung kopieren';
      share.addEventListener('click', async () => {
        const link = location.origin + location.pathname + '#vo';

        try {
          await navigator.clipboard.writeText(link);
          share.textContent = 'Kopiert';
        } catch (error) {
          share.textContent = 'Adresszeile kopieren';
        }

        setTimeout(() => { share.textContent = 'Link kopieren'; }, 2000);
      });
      head.append(el('span', 'release-kicker', kicker), share);
      body.append(head);
      body.append(el('h2', 'release-title', typeof info.title === 'string' && info.title !== '' ? info.title : project.title));

      if (typeof project.artist === 'string' && project.artist !== '') body.append(el('p', 'release-artist', project.artist));

      const facts = [dateText(info.date), typeof info.catalog === 'string' ? info.catalog : '', typeof info.license === 'string' ? info.license : ''].filter(Boolean);

      if (facts.length) body.append(el('p', 'release-facts', facts.join(' | ')));

      if (info.credits && typeof info.credits === 'object') {
        const list = el('dl', 'release-credits');

        for (const [role, names] of Object.entries(info.credits)) {
          if (!Array.isArray(names) || names.length === 0) continue;

          list.append(el('dt', null, role), el('dd', null, names.join(', ')));
        }

        if (list.children.length) body.append(list);
      }

      const progress = el('div', 'release-progress');
      seekBar = el('input', 'release-seek');
      seekBar.type = 'range';
      seekBar.min = '0';
      seekBar.max = '1000';
      seekBar.step = '1';
      seekBar.value = '0';
      seekBar.disabled = true;
      seekBar.setAttribute('aria-label', 'Position in der Veröffentlichung');
      timeOut = el('span', 'release-time', '0:00 / –:––');
      progress.append(seekBar, timeOut);
      body.append(progress);
      card.append(body);
    }

    let current = 'chip';
    let frameId = 0;
    let active = false;

    const stepOf = (version, audio) => (audio.currentTime - version.offset) / project.stepSeconds;

    function audioFor(version) {
      let audio = audios.get(version.id);

      if (!audio) {
        audio = new Audio();
        audio.preload = 'none';
        audio.src = version.url;
        audio.addEventListener('ended', () => {
          stopAudio();
        });
        audio.addEventListener('error', () => {
          stopAudio();
          note.textContent = 'Die Audiodatei lässt sich nicht laden.';
          note.hidden = false;
        });

        if (version.id === 'release' && seekBar) {
          const refresh = () => {
            const known = Number.isFinite(audio.duration) && audio.duration > 0;
            seekBar.disabled = !known;

            if (known && document.activeElement !== seekBar) seekBar.value = String(Math.round(audio.currentTime / audio.duration * 1000));

            timeOut.textContent = clock(audio.currentTime) + ' / ' + (known ? clock(audio.duration) : '–:––');
          };

          ['loadedmetadata', 'durationchange', 'timeupdate', 'seeked', 'emptied'].forEach(name => audio.addEventListener(name, refresh));
          seekBar.addEventListener('input', () => {
            if (Number.isFinite(audio.duration) && audio.duration > 0) {
              audio.currentTime = Number(seekBar.value) / 1000 * audio.duration;
              refresh();
            }
          });
        }
        audios.set(version.id, audio);
      }

      return audio;
    }

    function session(on) {
      if (!('mediaSession' in navigator) || typeof MediaMetadata !== 'function') return;

      try {
        const title = typeof info.title === 'string' && info.title !== '' ? info.title : project.title;

        navigator.mediaSession.metadata = on
          ? new MediaMetadata({ title, artist: project.artist || '', album: typeof info.label === 'string' ? info.label : '', artwork: coverUrl ? [{ src: coverUrl }] : [] })
          : null;
        navigator.mediaSession.setActionHandler('play', on ? () => { if (!active) deck.override(); } : null);
        navigator.mediaSession.setActionHandler('pause', on ? () => { if (active) stopAudio(); } : null);
      } catch (error) {
        //
      }
    }

    function stopAudio() {
      active = false;
      cancelAnimationFrame(frameId);
      audios.forEach(audio => audio.pause());
      deck.follow(null);
      deck.external(false);
    }

    function follow(version, audio) {
      cancelAnimationFrame(frameId);

      const loop = () => {
        if (audio.paused) return;

        if (version.sync) {
          const step = stepOf(version, audio);
          deck.follow(step >= 0 && step < project.steps ? step : null);
        }

        frameId = requestAnimationFrame(loop);
      };

      frameId = requestAnimationFrame(loop);
    }

    function startAudio(version, step) {
      const audio = audioFor(version);
      audio.volume = Math.max(0, Math.min(1, deck.st.volume));

      if (version.sync && step !== null) audio.currentTime = Math.max(0, version.offset + step * project.stepSeconds);
      else if (version.id !== 'release' || audio.ended) audio.currentTime = 0;

      if (version.id === 'release') session(true);

      note.hidden = true;
      active = true;
      deck.external(true);
      audio.play().then(() => {
        if (active) follow(version, audio);
      }).catch(() => {
        if (!active) return;

        stopAudio();
        note.textContent = 'Die Audiodatei lässt sich nicht abspielen.';
        note.hidden = false;
      });
    }

    function position() {
      if (current === 'chip') return deck.isPlaying() ? deck.currentStep() : null;

      const version = versions.find(item => item.id === current);
      const audio = audios.get(current);

      if (!version.sync || !audio || audio.paused) return null;

      const step = stepOf(version, audio);

      return step >= 0 && step < project.steps ? step : null;
    }

    function playing() {
      if (current === 'chip') return deck.isPlaying();

      return active;
    }

    async function choose(id) {
      if (id === current) return;

      const was = playing();
      const step = position();

      if (deck.isPlaying()) deck.stop(false);
      stopAudio();
      deck.external(false);
      current = id;

      if (id !== 'chip') deck.desk.reset();
      deck.desk.lock(id !== 'chip');
      deck.lockTracks(id !== 'chip');
      buttons.forEach((button, key) => button.setAttribute('aria-pressed', String(key === id)));
      note.hidden = true;

      try {
        history.replaceState(history.state, '', location.pathname + location.search + (HASHES[id] ? '#' + HASHES[id] : ''));
      } catch (error) {
        //
      }

      if (card) {
        const shown = id === 'release';
        const image = card.querySelector('img.cover');

        if (shown && image && !image.src) image.src = image.dataset.src;

        card.hidden = !shown;
        deck.arrangement.style.display = shown ? 'none' : '';
        deck.transport.classList.toggle('is-release', shown);
        if (!shown) session(false);
      }

      if (!was) return;

      if (id === 'chip') {
        await deck.play();
        if (step !== null) deck.seek(Math.min(step, project.steps - 1));
      } else {
        startAudio(versions.find(item => item.id === id), step);
      }
    }

    deck.override = () => {
      if (current === 'chip') return false;

      if (active) stopAudio();
      else startAudio(versions.find(item => item.id === current), null);

      return true;
    };

    if (versions.length > 1) {
      const group = el('div', 'versions');
      group.setAttribute('role', 'group');
      group.setAttribute('aria-label', 'Version wählen');

      for (const version of versions) {
        const button = el('button', 'version', version.label);
        button.type = 'button';
        button.title = version.note;
        button.setAttribute('aria-pressed', String(version.id === current));
        button.addEventListener('click', () => choose(version.id));
        buttons.set(version.id, button);
        group.append(button);
      }

      bar.append(group);
    }

    if (releaseUrl && !own) {
      const link = el('a', media.releaseLabel === 'SoundCloud' ? 'release soundcloud' : 'release', typeof media.releaseLabel === 'string' && media.releaseLabel !== '' ? media.releaseLabel.slice(0, 40) : 'Veröffentlichung');
      link.href = releaseUrl;
      link.rel = 'noopener noreferrer';
      link.target = '_blank';
      bar.append(link);
    }

    const fromHash = () => {
      const wanted = Object.keys(HASHES).find(id => HASHES[id] === location.hash.slice(1));

      if (wanted && versions.some(item => item.id === wanted)) choose(wanted);
    };

    fromHash();
    window.addEventListener('hashchange', fromHash);

    return { bar: bar.children.length ? bar : null, note, card };
  }

  function mountProject(project) {
    const host = {
      mix: false,
      open: async () => {
        initAudio();
        await ctx.resume();
        return master;
      },
    };
    const deck = projectDeck(project, S, host);

    const studio = studioPanel(deck, project);
    const top = el('div', 'deck-bar');
    top.append(deck.desk);

    if (studio.bar) top.append(studio.bar);

    root.replaceChildren(deck.transport, top, ...[studio.note, deck.arrangement, studio.card, midiLink(project)].filter(Boolean));
  }

  const PADS = [
    { id: 'lp', name: 'Tiefpass', key: '1', code: 'Digit1', kind: 'hold' },
    { id: 'hp', name: 'Hochpass', key: '2', code: 'Digit2', kind: 'hold' },
    { id: 'hall', name: 'Hall', key: '3', code: 'Digit3', kind: 'hold' },
    { id: 'dub', name: 'Dub-Echo', key: '4', code: 'Digit4', kind: 'hold' },
    { id: 'gate8', name: 'Gate 1/8', key: 'Q', code: 'KeyQ', kind: 'hold' },
    { id: 'gate16', name: 'Gate 1/16', key: 'W', code: 'KeyW', kind: 'hold' },
    { id: 'crush', name: 'Chip-Crush', key: 'E', code: 'KeyE', kind: 'hold' },
    { id: 'flange', name: 'Flanger', key: 'R', code: 'KeyR', kind: 'hold' },
    { id: 'phone', name: 'Telefon', key: 'A', code: 'KeyA', kind: 'hold' },
    { id: 'bass', name: 'Nur Bass', key: 'S', code: 'KeyS', kind: 'hold' },
    { id: 'wobble', name: 'Seekrank', key: 'D', code: 'KeyD', kind: 'hold' },
    { id: 'break', name: 'Break', key: 'F', code: 'KeyF', kind: 'hold' },
    { id: 'laser', name: 'Laser', key: 'Y', code: 'KeyZ', kind: 'shot' },
    { id: 'riser', name: 'Riser', key: 'X', code: 'KeyX', kind: 'hold' },
    { id: 'boom', name: 'Boom', key: 'C', code: 'KeyC', kind: 'shot' },
    { id: 'horn', name: 'Horn', key: 'V', code: 'KeyV', kind: 'shot' },
  ];

  function makeImpulse(seconds, decay) {
    const length = Math.floor(ctx.sampleRate * seconds);
    const buffer = ctx.createBuffer(2, length, ctx.sampleRate);

    for (let channelIndex = 0; channelIndex < 2; channelIndex++) {
      const data = buffer.getChannelData(channelIndex);
      for (let i = 0; i < length; i++) data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / length, decay);
    }

    return buffer;
  }

  function buildMachine(input, output, beat) {
    const smooth = 0.02;
    const switches = {};
    let node = input;

    function swap(id, build) {
      const entry = ctx.createGain();
      const sum = ctx.createGain();
      const dry = ctx.createGain();
      const wet = ctx.createGain();
      wet.gain.value = 0;
      node.connect(entry);
      entry.connect(dry);
      dry.connect(sum);
      build(entry).connect(wet);
      wet.connect(sum);
      node = sum;
      switches[id] = on => {
        dry.gain.setTargetAtTime(on ? 0 : 1, ctx.currentTime, smooth);
        wet.gain.setTargetAtTime(on ? 1 : 0, ctx.currentTime, smooth);
      };
    }

    function send(id, build, level) {
      const sum = ctx.createGain();
      const gate = ctx.createGain();
      gate.gain.value = 0;
      node.connect(sum);
      node.connect(gate);
      build(gate).connect(sum);
      node = sum;
      switches[id] = on => gate.gain.setTargetAtTime(on ? level : 0, ctx.currentTime, smooth);
    }

    function sweep(id, type, neutral, target, seconds) {
      const filter = ctx.createBiquadFilter();
      filter.type = type;
      filter.frequency.value = neutral;
      filter.Q.value = 3;
      node.connect(filter);
      node = filter;
      switches[id] = on => {
        filter.frequency.cancelScheduledValues(ctx.currentTime);
        filter.frequency.setTargetAtTime(on ? target : neutral, ctx.currentTime, on ? seconds : 0.05);
      };
    }

    sweep('lp', 'lowpass', 20000, 250, 0.45);
    sweep('hp', 'highpass', 20, 3000, 0.45);

    send('hall', inp => {
      const convolver = ctx.createConvolver();
      convolver.buffer = makeImpulse(2.2, 2.5);
      inp.connect(convolver);
      return convolver;
    }, 0.9);

    send('dub', inp => {
      const delay = ctx.createDelay(2);
      delay.delayTime.value = 0.35;
      const feedback = ctx.createGain();
      feedback.gain.value = 0.68;
      const damp = ctx.createBiquadFilter();
      damp.type = 'lowpass';
      damp.frequency.value = 2200;
      inp.connect(delay);
      delay.connect(damp);
      damp.connect(feedback);
      feedback.connect(delay);
      //
      setInterval(() => {
        if (ctx && ctx.state === 'running') delay.delayTime.setTargetAtTime(Math.min(1.9, beat() * 0.75), ctx.currentTime, 0.1);
      }, 250);
      return damp;
    }, 0.8);

    function gate(id, perBeat) {
      const level = ctx.createGain();
      level.gain.value = 1;
      const lfo = ctx.createOscillator();
      lfo.type = 'square';
      lfo.frequency.value = 2;
      const depth = ctx.createGain();
      depth.gain.value = 0;
      lfo.connect(depth);
      depth.connect(level.gain);
      lfo.start();
      node.connect(level);
      node = level;
      switches[id] = on => {
        lfo.frequency.setValueAtTime(perBeat / beat(), ctx.currentTime);
        level.gain.setTargetAtTime(on ? 0.5 : 1, ctx.currentTime, smooth);
        depth.gain.setTargetAtTime(on ? 0.5 : 0, ctx.currentTime, smooth);
      };
    }

    gate('gate8', 1);
    gate('gate16', 2);

    swap('crush', inp => {
      const steps = 5;
      const curve = new Float32Array(2048);
      for (let i = 0; i < curve.length; i++) {
        const x = (i / (curve.length - 1)) * 2 - 1;
        curve[i] = Math.round(x * steps) / steps;
      }
      const shaper = ctx.createWaveShaper();
      shaper.curve = curve;
      inp.connect(shaper);
      return shaper;
    });

    send('flange', inp => {
      const delay = ctx.createDelay(0.05);
      delay.delayTime.value = 0.004;
      const lfo = ctx.createOscillator();
      lfo.frequency.value = 0.35;
      const depth = ctx.createGain();
      depth.gain.value = 0.003;
      const feedback = ctx.createGain();
      feedback.gain.value = 0.6;
      lfo.connect(depth);
      depth.connect(delay.delayTime);
      lfo.start();
      inp.connect(delay);
      delay.connect(feedback);
      feedback.connect(delay);
      return delay;
    }, 1);

    swap('phone', inp => {
      const high = ctx.createBiquadFilter();
      high.type = 'highpass';
      high.frequency.value = 500;
      const low = ctx.createBiquadFilter();
      low.type = 'lowpass';
      low.frequency.value = 2800;
      const boost = ctx.createGain();
      boost.gain.value = 1.6;
      inp.connect(high);
      high.connect(low);
      low.connect(boost);
      return boost;
    });

    swap('bass', inp => {
      const low = ctx.createBiquadFilter();
      low.type = 'lowpass';
      low.frequency.value = 170;
      low.Q.value = 0.7;
      inp.connect(low);
      return low;
    });

    swap('wobble', inp => {
      const delay = ctx.createDelay(0.1);
      delay.delayTime.value = 0.012;
      const lfo = ctx.createOscillator();
      lfo.frequency.value = 0.9;
      const depth = ctx.createGain();
      depth.gain.value = 0.006;
      lfo.connect(depth);
      depth.connect(delay.delayTime);
      lfo.start();
      inp.connect(delay);
      return delay;
    });

    const cut = ctx.createGain();
    node.connect(cut);
    node = cut;
    switches.break = on => cut.gain.setTargetAtTime(on ? 0 : 1, ctx.currentTime, 0.01);

    node.connect(output);

    let riser = null;

    switches.riser = on => {
      if (on && !riser) {
        const source = ctx.createBufferSource();
        source.buffer = noise;
        source.loop = true;
        const band = ctx.createBiquadFilter();
        band.type = 'bandpass';
        band.Q.value = 2.5;
        band.frequency.setValueAtTime(250, ctx.currentTime);
        band.frequency.exponentialRampToValueAtTime(9000, ctx.currentTime + 4);
        const level = ctx.createGain();
        level.gain.setValueAtTime(0, ctx.currentTime);
        level.gain.linearRampToValueAtTime(0.5, ctx.currentTime + 4);
        source.connect(band);
        band.connect(level);
        level.connect(output);
        source.start();
        riser = { source, level };
      } else if (!on && riser) {
        const { source, level } = riser;
        riser = null;
        level.gain.cancelScheduledValues(ctx.currentTime);
        level.gain.setTargetAtTime(0, ctx.currentTime, 0.05);
        source.stop(ctx.currentTime + 0.4);
      }
    };

    const shots = {
      laser() {
        const now = ctx.currentTime;
        const osc = ctx.createOscillator();
        const level = ctx.createGain();
        osc.type = 'square';
        osc.frequency.setValueAtTime(2400, now);
        osc.frequency.exponentialRampToValueAtTime(90, now + 0.28);
        level.gain.setValueAtTime(0.28, now);
        level.gain.exponentialRampToValueAtTime(0.001, now + 0.3);
        osc.connect(level);
        level.connect(output);
        osc.start(now);
        osc.stop(now + 0.32);
      },
      boom() {
        const now = ctx.currentTime;
        const osc = ctx.createOscillator();
        const level = ctx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(110, now);
        osc.frequency.exponentialRampToValueAtTime(28, now + 0.9);
        level.gain.setValueAtTime(1.1, now);
        level.gain.exponentialRampToValueAtTime(0.001, now + 1.1);
        osc.connect(level);
        level.connect(output);
        osc.start(now);
        osc.stop(now + 1.15);

        const crash = ctx.createBufferSource();
        crash.buffer = noise;
        const low = ctx.createBiquadFilter();
        low.type = 'lowpass';
        low.frequency.setValueAtTime(5000, now);
        low.frequency.exponentialRampToValueAtTime(200, now + 0.8);
        const burst = ctx.createGain();
        burst.gain.setValueAtTime(0.5, now);
        burst.gain.exponentialRampToValueAtTime(0.001, now + 0.8);
        crash.connect(low);
        low.connect(burst);
        burst.connect(output);
        crash.start(now);
        crash.stop(now + 0.85);
      },
      horn() {
        const now = ctx.currentTime;
        const level = ctx.createGain();
        level.gain.setValueAtTime(0, now);
        level.gain.linearRampToValueAtTime(0.22, now + 0.02);
        level.gain.setValueAtTime(0.22, now + 0.55);
        level.gain.linearRampToValueAtTime(0, now + 0.75);
        level.connect(output);

        [440, 554.37, 659.25].forEach(frequency => {
          const osc = ctx.createOscillator();
          osc.type = 'sawtooth';
          osc.frequency.setValueAtTime(frequency, now);
          osc.frequency.linearRampToValueAtTime(frequency * 0.97, now + 0.75);
          osc.connect(level);
          osc.start(now);
          osc.stop(now + 0.78);
        });
      },
    };

    return {
      set(id, on) {
        if (switches[id]) switches[id](on);
      },
      shot(id) {
        if (shots[id]) shots[id]();
      },
    };
  }

  function mountMix(data) {
    const catalog = data.catalog;
    const names = ['A', 'B'];
    const states = [makeState(), makeState()];
    const decks = [null, null];
    const levels = [1, 1];
    let masterLevel = 0.9;
    let volumes = null;
    let faders = null;
    let channels = null;
    let masterGain = null;
    let machine = null;
    let position = 50;
    let analysers = null;

    const SEGMENTS = 12;
    const meters = [0, 1].map(index => {
      const column = el('div', 'vu');
      column.setAttribute('aria-hidden', 'true');
      column.title = 'Pegel Deck ' + names[index];
      const segments = Array.from({ length: SEGMENTS }, () => {
        const node = el('i');
        column.append(node);
        return node;
      });
      return { column, segments, held: 0, lit: -1 };
    });
    const samples = new Float32Array(512);

    function drawMeters() {
      if (!analysers) return;

      analysers.forEach((analyser, index) => {
        analyser.getFloatTimeDomainData(samples);

        let sum = 0;
        for (let i = 0; i < samples.length; i++) sum += samples[i] * samples[i];

        const level = Math.max(0, Math.min(1, (20 * Math.log10(Math.sqrt(sum / samples.length) + 1e-6) + 42) / 42));
        const meter = meters[index];
        meter.held = Math.max(level, meter.held - 0.03);
        const lit = Math.round(meter.held * SEGMENTS);

        if (lit !== meter.lit) {
          meter.segments.forEach((node, i) => node.classList.toggle('on', i < lit));
          meter.lit = lit;
        }
      });

      requestAnimationFrame(drawMeters);
    }

    function gains() {
      const angle = (position / 100) * Math.PI / 2;

      return [Math.cos(angle), Math.sin(angle)];
    }

    function ramp(param, value, instant) {
      if (instant) param.value = value;
      else param.setTargetAtTime(value, ctx.currentTime, 0.015);
    }

    function applyFader(instant) {
      if (!ctx || !faders) return;

      const values = gains();

      faders.forEach((node, index) => ramp(node.gain, values[index], instant));
    }

    function applyLevels(instant) {
      if (!ctx || !volumes) return;

      volumes.forEach((node, index) => ramp(node.gain, levels[index], instant));
      ramp(masterGain.gain, masterLevel, instant);
    }

    function beatSeconds() {
      const playing = decks.find(deck => deck && deck.playing());

      return playing ? playing.st.fx.beat : 0.47;
    }

    async function open(index) {
      if (!ctx) {
        ctx = new (window.AudioContext || window.webkitAudioContext)();
        makeNoise();

        const limiter = makeLimiter();
        limiter.connect(ctx.destination);
        masterGain = ctx.createGain();
        masterGain.connect(limiter);

        const bus = ctx.createGain();
        machine = buildMachine(bus, masterGain, beatSeconds);

        volumes = states.map(() => ctx.createGain());
        faders = states.map(() => {
          const node = ctx.createGain();
          node.connect(bus);
          return node;
        });
        volumes.forEach((node, i) => node.connect(faders[i]));
        channels = states.map((st, i) => buildChannel(st, volumes[i]));
        analysers = faders.map(node => {
          const analyser = ctx.createAnalyser();
          analyser.fftSize = 512;
          node.connect(analyser);
          return analyser;
        });
        applyFader(true);
        requestAnimationFrame(drawMeters);
        applyLevels(true);
      }

      await ctx.resume();

      return channels[index];
    }

    function sync(own, exact = true) {
      const other = decks.find(deck => deck && deck !== own);

      if (!other || !own.project) return null;

      let best = null;

      [1, 2, 0.5].forEach(factor => {
        const percentValue = ((other.bpm() * factor) / own.project.bpm - 1) * 100;
        if (best === null || Math.abs(percentValue) < Math.abs(best)) best = percentValue;
      });

      const applied = own.desk.setTempo(best, exact);
      status.textContent = Math.abs(applied - best) > 0.05 ? 'Das Tempo reicht nur bis \u00b120 %, ganz erreicht es Deck ' + names[decks.indexOf(own)] + ' nicht.' : '';

      return other;
    }

    function autoStart(own) {
      const other = sync(own, true);

      if (other && own.playing() && other.playing()) own.align(other);
    }

    let realign = 0;

    function tempoChanged(leader) {
      decks.forEach(deck => deck && deck !== leader && deck.auto && sync(deck, true));

      clearTimeout(realign);
      realign = setTimeout(() => {
        decks.forEach(deck => {
          if (deck && deck !== leader && deck.auto && deck.playing() && leader.playing()) deck.align(leader);
        });
      }, 500);
    }

    const toolButton = (label, aria, onClick) => {
      const button = el('button', 'skip tool', label);
      button.type = 'button';
      button.title = aria;
      button.setAttribute('aria-label', aria);
      button.addEventListener('click', onClick);
      return button;
    };

    const playAll = toolButton('\u25b6\ufe0e', 'Beide Decks spielen', async () => {
      const loaded = decks.filter(Boolean);

      if (loaded.length === 0) return;

      if (loaded.some(deck => deck.playing())) {
        loaded.forEach(deck => deck.playing() && deck.toggleTransport());
        return;
      }

      await open(0);
      const when = ctx.currentTime + 0.15;
      loaded.forEach(deck => deck.play(when));
    });
    playAll.setAttribute('aria-pressed', 'false');

    function refreshTogether() {
      const any = decks.some(deck => deck && deck.playing());
      playAll.textContent = any ? '\u23f8\ufe0e' : '\u25b6\ufe0e';
      playAll.title = any ? 'Beide Decks pausieren' : 'Beide Decks spielen';
      playAll.setAttribute('aria-label', playAll.title);
      playAll.setAttribute('aria-pressed', String(any));
    }

    const lightButton = toolButton('', '', () => {
      lightIndex = (lightIndex + 1) % LIGHTS.length;
      applyLight();

      try {
        localStorage.setItem('chiptune-light', LIGHTS[lightIndex][0]);
      } catch (error) {
        //
      }
    });

    function applyLight() {
      const [id, label, title] = LIGHTS[lightIndex];
      wrapper.setAttribute('data-light', id);
      lightButton.textContent = label;
      lightButton.title = title + ' (Klick wechselt)';
      lightButton.setAttribute('aria-label', lightButton.title);
    }

    const rewindAll = toolButton('\u23ee\ufe0e', 'Beide Decks an den Anfang', () => decks.forEach(deck => deck && deck.seek(0)));

    const copyTempo = (from, to) => toolButton(names[from] + '\u2192' + names[to], 'Tempo von Deck ' + names[from] + ' auf Deck ' + names[to] + ' übertragen', () => {
      if (decks[from] && decks[to]) sync(decks[to], true);
    });

    const wrapper = el('div', 'decks');

    const LIGHTS = [['ambi', 'Ambi', 'Rahmenlicht: bunt (Ambilight)'], ['warm', 'Warm', 'Rahmenlicht: warmweiß'], ['off', 'Aus', 'Rahmenlicht: aus']];
    let lightIndex = 0;

    try {
      const saved = LIGHTS.findIndex(entry => entry[0] === localStorage.getItem('chiptune-light'));
      if (saved >= 0) lightIndex = saved;
    } catch (error) {
      //
    }

    applyLight();

    const status = el('p', 'mix-status');
    status.setAttribute('role', 'status');

    const idleProject = { title: '', bpm: 120, stepsPerBeat: 4, stepsPerBar: 16, stepSeconds: 0.125, loop: false, steps: 128, tracks: [] };

    const loaders = [];
    const loadedIds = ['', ''];
    const overlay = el('div', 'picker-overlay');
    overlay.hidden = true;
    const picker = el('div', 'picker');
    picker.setAttribute('role', 'dialog');
    picker.setAttribute('aria-modal', 'true');
    picker.setAttribute('aria-label', 'Song wählen');
    overlay.append(picker);
    let pickerOpener = null;
    const pickerTitle = el('b', 'picker-title');
    const pickerClose = el('button', null, '\u00d7');
    pickerClose.type = 'button';
    pickerClose.setAttribute('aria-label', 'Schließen');
    const pickerHead = el('div', 'picker-head');
    pickerHead.append(pickerTitle, pickerClose);
    const filters = el('nav', 'picker-filters');
    filters.setAttribute('aria-label', 'Quellen und Alben');
    const search = el('input');
    search.type = 'search';
    search.placeholder = 'Suchen';
    search.setAttribute('aria-label', 'Songs suchen');
    const list = el('ul', 'picker-list');
    const pickerMain = el('div', 'picker-main');
    pickerMain.append(search, list);
    const pickerBody = el('div', 'picker-body');
    pickerBody.append(filters, pickerMain);
    picker.append(pickerHead, pickerBody);

    let pickerTarget = 0;
    let filter = { type: 'all', id: '' };

    const sources = [...new Map(catalog.map(entry => [entry.sourceId, entry.source])).entries()].sort((a, b) => a[1].localeCompare(b[1], 'de'));
    const groupsList = [...new Map(catalog.filter(entry => entry.groupId).map(entry => [entry.groupId, entry.group + ' (' + entry.source + ')'])).entries()].sort((a, b) => a[1].localeCompare(b[1], 'de'));
    const albumsList = [...new Map(catalog.filter(entry => entry.albumId).map(entry => [entry.albumId, entry.album + ' (' + entry.source + ')'])).entries()].sort((a, b) => a[1].localeCompare(b[1], 'de'));

    function closePicker() {
      overlay.hidden = true;
      if (pickerOpener) pickerOpener.focus();
    }

    function inFilter(entry) {
      if (filter.type === 'source') return entry.sourceId === filter.id;
      if (filter.type === 'album') return entry.albumId === filter.id;
      if (filter.type === 'group') return entry.groupId === filter.id;
      if (filter.type === 'single') return entry.albumId === '' && !entry.groupId;

      return true;
    }

    function renderFilters() {
      filters.replaceChildren();

      const add = (label, type, id) => {
        const count = catalog.filter(entry => (type === 'all' ? true : type === 'source' ? entry.sourceId === id : type === 'album' ? entry.albumId === id : type === 'group' ? entry.groupId === id : entry.albumId === '' && !entry.groupId)).length;
        const button = el('button', 'picker-filter');
        button.type = 'button';
        button.append(el('span', null, label), el('b', null, String(count)));
        button.setAttribute('aria-pressed', String(filter.type === type && filter.id === id));
        button.addEventListener('click', () => {
          filter = { type, id };
          renderFilters();
          renderList();
        });
        filters.append(button);
      };

      add('Alles', 'all', '');

      if (sources.length > 1) {
        filters.append(el('span', 'picker-group', 'Quellen'));
        sources.forEach(([id, title]) => add(title, 'source', id));
      }

      if (groupsList.length > 0) {
        filters.append(el('span', 'picker-group', 'Gruppen'));
        groupsList.forEach(([id, title]) => add(title, 'group', id));
      }

      if (albumsList.length > 0) {
        filters.append(el('span', 'picker-group', 'Alben'));
        albumsList.forEach(([id, title]) => add(title, 'album', id));
      }

      if ((albumsList.length > 0 || groupsList.length > 0) && catalog.some(entry => entry.albumId === '' && !entry.groupId)) {
        if (albumsList.length === 0) filters.append(el('span', 'picker-group', 'Sonstige'));
        add('Einzelne Songs', 'single', '');
      }
    }

    function visibleSongs() {
      const query = search.value.trim().toLowerCase();

      return catalog
        .filter(inFilter)
        .filter(entry => query === '' || [entry.title, entry.artist, entry.album, entry.group, entry.source, ...entry.tags].join(' ').toLowerCase().includes(query))
        .sort((a, b) => a.source.localeCompare(b.source, 'de') || (a.albumId === '' ? 1 : 0) - (b.albumId === '' ? 1 : 0) || a.album.localeCompare(b.album, 'de') || (a.group === '' ? 1 : 0) - (b.group === '' ? 1 : 0) || a.group.localeCompare(b.group, 'de') || a.title.localeCompare(b.title, 'de', { numeric: true }));
    }

    function chooseSong(entry) {
      closePicker();
      if (loaders[pickerTarget]) loaders[pickerTarget](entry);
    }

    function renderList() {
      const songs = visibleSongs();
      list.replaceChildren();

      if (songs.length === 0) {
        list.append(el('li', 'picker-empty', 'Nichts gefunden.'));
        return;
      }

      songs.forEach(entry => {
        const item = el('li');
        const button = el('button', 'picker-item');
        button.type = 'button';
        const bpm = Number(Number(entry.bpm).toFixed(1));
        const meta = [entry.album || entry.group || entry.source, entry.artist, bpm + ' BPM', Number(entry.bars) + ' Bars'].filter(Boolean).join(' | ');
        button.append(el('b', null, entry.title), el('small', null, meta));
        if (loadedIds.includes(entry.id)) button.setAttribute('aria-current', 'true');
        button.addEventListener('click', () => chooseSong(entry));
        item.append(button);
        list.append(item);
      });
    }

    function openPicker(index) {
      pickerTarget = index;
      pickerTitle.textContent = 'Deck ' + names[index] + ': Song wählen';
      search.value = '';
      renderFilters();
      renderList();

      pickerOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
      overlay.hidden = false;

      search.focus();
    }

    search.addEventListener('input', renderList);
    search.addEventListener('keydown', event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        const first = visibleSongs()[0];
        if (first) chooseSong(first);
      }
    });
    pickerClose.addEventListener('click', closePicker);
    overlay.addEventListener('click', event => {
      if (event.target === overlay) closePicker();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !overlay.hidden) {
        event.preventDefault();
        closePicker();
      }
    });

    function hostFor(index, box) {
      return {
        mix: true,
        open: () => open(index),
        sync,
        autoStart,
        tempoChanged,
        changed: refreshTogether,
        beat: n => (n < 0 ? box().removeAttribute('data-pulse') : box().setAttribute('data-pulse', String(n))),
      };
    }

    function fill(body, index, deck, idle) {
      const frame = el('div', 'arrangement-box');
      frame.append(deck.arrangement);

      if (idle) frame.append(el('p', 'deck-hint', 'Song wählen'));

      body.classList.toggle('idle', idle);
      body.inert = idle;
      body.replaceChildren(deck.transport, deck.controls, deck.desk, frame);
    }

    function slot(index) {
      const box = el('section', 'deck');
      box.setAttribute('aria-label', 'Deck ' + names[index]);

      const head = el('div', 'deck-head');
      const songButton = el('button', 'deck-song', 'Song wählen');
      songButton.type = 'button';
      songButton.setAttribute('aria-label', 'Song für Deck ' + names[index] + ' wählen');
      songButton.addEventListener('click', () => openPicker(index));

      const volume = el('label', 'deck-volume');
      const slider = el('input');
      slider.type = 'range';
      slider.min = '0';
      slider.max = '100';
      slider.step = '1';
      slider.value = '100';
      slider.setAttribute('aria-label', 'Lautstärke Deck ' + names[index]);
      const volumeOut = el('b', null, '100 %');
      slider.addEventListener('input', () => {
        levels[index] = Number(slider.value) / 100;
        volumeOut.textContent = slider.value + ' %';
        applyLevels(false);
      });
      slider.addEventListener('dblclick', () => {
        slider.value = '100';
        slider.dispatchEvent(new Event('input'));
      });
      volume.append(el('span', null, 'Vol'), slider, volumeOut);

      const eject = el('button', 'skip eject', '\u23cf');
      eject.type = 'button';
      eject.disabled = true;
      eject.title = 'Song auswerfen';
      eject.setAttribute('aria-label', 'Song aus Deck ' + names[index] + ' auswerfen');
      eject.addEventListener('click', () => {
        if (decks[index]) decks[index].stop(false);

        decks[index] = null;
        const st = states[index];
        const nodes = st.nodes;
        Object.assign(st, makeState());
        st.nodes = nodes;
        applyFx(st, true);
        songButton.textContent = 'Song wählen';
        songButton.title = '';
        loadedIds[index] = '';
        eject.disabled = true;
        fill(body, index, projectDeck(idleProject, states[index], hostFor(index, () => box)), true);
        status.textContent = '';
        refreshTogether();
      });

      head.append(el('b', 'deck-name', 'Deck ' + names[index]), songButton, eject, volume);

      const body = el('div', 'deck-body');
      fill(body, index, projectDeck(idleProject, states[index], hostFor(index, () => box)), true);
      box.append(head, body);

      loaders[index] = async entry => {
        status.textContent = '';

        try {
          const response = await fetch(entry.data);

          if (!response.ok) throw new Error(String(response.status));

          const project = await response.json();

          if (decks[index]) decks[index].stop(false);

          const st = states[index];
          const nodes = st.nodes;
          Object.assign(st, makeState());
          st.nodes = nodes;
          applyFx(st, true);

          const deck = projectDeck(project, st, { ...hostFor(index, () => box), key: entry.id });
          decks[index] = deck;
          eject.disabled = false;
          loadedIds[index] = entry.id;
          songButton.textContent = entry.title;
          songButton.title = entry.title + (entry.album ? ' | ' + entry.album : '') + ' | ' + entry.source;
          fill(body, index, deck, false);
          refreshTogether();
        } catch (error) {
          console.error(error);
          status.textContent = 'Der Song „' + entry.title + '“ konnte nicht geladen werden.';
        }
      };

      return box;
    }

    wrapper.append(slot(0), slot(1));

    const machineBox = el('section', 'machine');
    machineBox.setAttribute('aria-label', 'Master und Effekte');

    const machineHead = el('div', 'machine-head');
    const fold = el('button', 'machine-fold');
    fold.type = 'button';
    fold.append(el('span', 'machine-arrow'), el('b', 'machine-name', 'Effekt-Maschine'));
    const masterLabel = el('label', 'master');
    const masterSlider = el('input');
    masterSlider.type = 'range';
    masterSlider.min = '0';
    masterSlider.max = '120';
    masterSlider.step = '1';
    masterSlider.value = String(Math.round(masterLevel * 100));
    masterSlider.setAttribute('aria-label', 'Master-Lautstärke');
    const masterOut = el('b', null, masterSlider.value + ' %');
    masterSlider.addEventListener('input', () => {
      masterLevel = Number(masterSlider.value) / 100;
      masterOut.textContent = masterSlider.value + ' %';
      applyLevels(false);
    });
    masterLabel.append(el('span', null, 'Master'), masterSlider, masterOut);

    const latch = pressButton('Halten', 'Pads rasten ein, statt nur beim Drücken zu wirken');
    latch.classList.add('latch');
    let latched = false;
    latch.addEventListener('click', () => {
      latched = !latched;
      latch.setAttribute('aria-pressed', String(latched));
    });

    machineHead.append(fold, masterLabel, latch);

    const grid = el('div', 'pads');
    const active = new Set();
    const buttons = new Map();

    async function setPad(pad, on) {
      if (!ctx) await open(0);
      if (!machine) return;

      if (pad.kind === 'shot') {
        if (on) machine.shot(pad.id);
        return;
      }

      if (on) active.add(pad.id);
      else active.delete(pad.id);

      machine.set(pad.id, on);
      buttons.get(pad.id).setAttribute('aria-pressed', String(on));
    }

    PADS.forEach((pad, index) => {
      const button = el('button', 'pad');
      button.type = 'button';
      button.style.setProperty('--h', String(Math.round((index / PADS.length) * 340)));
      button.setAttribute('aria-pressed', 'false');
      button.setAttribute('aria-label', pad.name + ' (Taste ' + pad.key + ')');
      button.append(el('span', 'pad-name', pad.name), el('span', 'pad-key', pad.key));

      const press = () => {
        if (latched && pad.kind === 'hold') setPad(pad, !active.has(pad.id));
        else setPad(pad, true);
      };
      const release = () => {
        if (!latched && pad.kind === 'hold' && active.has(pad.id)) setPad(pad, false);
        button.classList.remove('hit');
      };

      button.addEventListener('pointerdown', event => {
        event.preventDefault();
        button.classList.add('hit');
        press();
      });
      ['pointerup', 'pointerleave', 'pointercancel'].forEach(name => button.addEventListener(name, release));
      button.addEventListener('keydown', event => {
        if ((event.key === ' ' || event.key === 'Enter') && !event.repeat) {
          event.preventDefault();
          press();
        }
      });
      button.addEventListener('keyup', event => {
        if (event.key === ' ' || event.key === 'Enter') release();
      });
      button.addEventListener('blur', release);

      buttons.set(pad.id, button);
      grid.append(button);
    });

    function typing(target) {
      return target instanceof Element && (target.closest('input, textarea, select') !== null || overlay.contains(target));
    }

    document.addEventListener('keydown', event => {
      if (event.ctrlKey || event.metaKey || event.altKey || event.repeat) return;

      if (typing(event.target)) return;

      const pad = PADS.find(item => item.code === event.code);

      if (!pad) return;

      event.preventDefault();
      buttons.get(pad.id).classList.add('hit');
      if (latched && pad.kind === 'hold') setPad(pad, !active.has(pad.id));
      else setPad(pad, true);
    });

    document.addEventListener('keyup', event => {
      const pad = PADS.find(item => item.code === event.code);

      if (!pad) return;

      buttons.get(pad.id).classList.remove('hit');

      if (!latched && pad.kind === 'hold' && active.has(pad.id)) setPad(pad, false);
    });

    machineBox.append(machineHead, grid);

    function openMachine(open) {
      grid.hidden = !open;
      latch.hidden = !open;
      machineBox.classList.toggle('closed', !open);
      fold.setAttribute('aria-expanded', String(open));
      fold.title = open ? 'Effekt-Maschine einklappen' : 'Effekt-Maschine ausklappen';

      try {
        localStorage.setItem('chiptune-machine', open ? 'open' : 'closed');
      } catch (error) {
        //
      }
    }

    let machineOpen = true;

    try {
      machineOpen = localStorage.getItem('chiptune-machine') !== 'closed';
    } catch (error) {
      //
    }

    openMachine(machineOpen);
    fold.addEventListener('click', () => openMachine(grid.hidden));

    const fader = el('div', 'crossfader');
    const faderBox = el('div', 'fader-box');
    const slider = el('input');
    slider.type = 'range';
    slider.min = '0';
    slider.max = '100';
    slider.step = '1';
    slider.value = '50';
    slider.setAttribute('aria-label', 'Crossfader, links Deck A, rechts Deck B');

    function moveTo(value) {
      position = value;
      slider.value = String(value);
      applyFader(false);
    }

    slider.addEventListener('input', () => moveTo(Number(slider.value)));
    slider.addEventListener('dblclick', () => moveTo(50));

    const quick = (label, value, aria) => {
      const button = el('button', 'skip', label);
      button.type = 'button';
      button.setAttribute('aria-label', aria);
      button.addEventListener('click', () => moveTo(value));
      return button;
    };

    const track = el('div', 'fader-track');
    track.append(slider);
    const faderBody = el('div', 'fader-body');
    faderBody.append(meters[0].column, track, meters[1].column);

    const group = el('div', 'fader-tools');
    const session = { name: '', start: 0 };

    function mixHash() {
      const query = new URLSearchParams();

      if (loadedIds[0]) query.set('a', loadedIds[0]);
      if (loadedIds[1]) query.set('b', loadedIds[1]);
      if (session.start > 0) query.set('start', String(session.start));
      query.set('auto', decks[1] && decks[1].auto ? '1' : '0');
      query.set('fader', String(Math.round(position)));

      return query.toString();
    }

    const linkButton = toolButton('Link', 'Mixer-Zustand als Link kopieren: Songs, Auto und Crossfader', async () => {
      const hash = mixHash();
      const url = location.origin + location.pathname + '#' + hash;

      try {
        history.replaceState(null, '', '#' + hash);
      } catch (error) {
        //
      }

      try {
        await navigator.clipboard.writeText(url);
        status.textContent = 'Link kopiert.';
      } catch (error) {
        status.textContent = url;
      }
    });

    group.append(playAll, rewindAll, copyTempo(0, 1), copyTempo(1, 0), lightButton, linkButton);

    faderBox.append(
      quick('A', 0, 'Crossfader ganz zu Deck A'),
      faderBody,
      quick('B', 100, 'Crossfader ganz zu Deck B'),
      quick('Mitte', 50, 'Crossfader in die Mitte'),
      group,
    );
    fader.append(faderBox);

    wrapper.replaceChildren(wrapper.children[0], fader, wrapper.children[1]);

    root.replaceChildren(wrapper, status, machineBox, overlay);

    async function startSession() {
      const first = decks[0];
      const second = decks[1];

      if (!first) return;

      await open(0);
      decks.forEach(deck => deck && deck.playing() && deck.stop(false));
      first.seek(0);
      if (second) second.seek(0);
      if (second && second.auto) sync(second, true);

      const begin = ctx.currentTime + 0.4;
      first.play(begin);
      if (second) second.play(begin + (Math.max(1, session.start) - 1) * first.barSeconds());
    }

    function showSessionBar() {
      const bar = el('div', 'session-bar');
      const play = el('button', 'play', 'Session starten');
      play.type = 'button';
      play.addEventListener('click', startSession);
      const note = session.start > 1 ? 'Deck B steigt auf Takt ' + session.start + ' von Deck A ein.' : 'Beide Decks starten zusammen.';
      bar.append(el('b', null, session.name || 'Session'), el('span', null, note), play);
      root.prepend(bar);
    }

    async function openFromLink() {
      const params = new URLSearchParams(location.hash.slice(1));
      const byId = id => catalog.find(entry => entry.id === id);
      const first = byId(params.get('a'));
      const second = byId(params.get('b'));

      if (!first && !second) return;

      if (first) await loaders[0](first);
      if (second) await loaders[1](second);

      const fader = Number(params.get('fader'));
      if (params.has('fader') && fader >= 0 && fader <= 100) moveTo(Math.round(fader));
      if (decks[1] && params.get('auto') === '1') decks[1].setAuto(true);

      const start = parseInt(params.get('start') || '', 10);

      if (decks[0] && start >= 1) {
        session.start = start;
        session.name = (params.get('name') || '').slice(0, 80);
        showSessionBar();
      }
    }

    openFromLink();
  }

  function mountSet(set) {
    const parts = set.parts;
    const cards = parts.map((_, index) => document.getElementById('part-' + (index + 1)));
    const bars = cards.map(card => (card ? card.querySelector('.part-progress') : null));

    let marks = [];
    let timer = null;
    let frameId = 0;
    let nextTime = 0;
    let nextPart = 0;
    let nextRepeat = 0;
    let nextStep = 0;
    let ended = false;
    let latch = null;
    let current = 0;
    let playing = false;
    let starting = false;

    const playButton = el('button', 'play', 'Play');
    playButton.type = 'button';
    playButton.setAttribute('aria-keyshortcuts', 'Space');

    const prevButton = el('button', 'skip', 'Prev');
    prevButton.type = 'button';
    prevButton.setAttribute('aria-label', 'Vorheriger Part');

    const nextButton = el('button', 'skip', 'Next');
    nextButton.type = 'button';
    nextButton.setAttribute('aria-label', 'Nächster Part');

    let looping = Boolean(set.loop);

    const loopButton = el('button', 'loop', 'Endlos');
    loopButton.type = 'button';
    loopButton.setAttribute('aria-pressed', String(looping));
    loopButton.addEventListener('click', () => {
      looping = !looping;
      loopButton.setAttribute('aria-pressed', String(looping));
    });

    const position = el('div', 'position');
    position.setAttribute('role', 'timer');
    const partOut = el('b', null, '1');
    const barOut = el('b', null, '1');
    position.append(document.createTextNode((set.album ? 'Song ' : 'Part ')), partOut, document.createTextNode('/' + parts.length + ' Bar '), barOut);

    const bpmOut = el('b', null, bpmText(parts[0].bpm));
    const tempo = el('span', 'tempo');
    tempo.append(bpmOut, document.createTextNode(' BPM'));

    const transport = el('div', 'transport');
    transport.append(playButton, prevButton, nextButton, loopButton, position, tempo);

    const desk = deskControl(S, () => {
      bpmOut.textContent = bpmText(parts[current].bpm);
    }, { onVolume: setSongVolume });
    root.replaceChildren(transport, desk);

    const layout = parts.map(part => {
      const runSteps = part.stop - part.start;
      const byStep = Array.from({ length: runSteps }, () => []);

      part.tracks.forEach((track, index) => track.events.forEach(event => {
        if (event.t >= part.start && event.t < part.stop) {
          byStep[event.t - part.start].push([index, Object.assign({}, event, { len: Math.min(event.len, part.stop - event.t) })]);
        }
      }));

      return { runSteps, byStep, buses: [] };
    });

    function renderState() {
      playButton.textContent = playing ? 'Stop' : 'Play';
      playButton.classList.toggle('on', playing);

      if (!playing) {
        partOut.textContent = '1';
        barOut.textContent = String(parts[0].startBar);
        bpmOut.textContent = bpmText(parts[0].bpm);
        cards.forEach(card => card && card.classList.remove('active'));
        bars.forEach(bar => bar && (bar.style.width = '0%'));
      }
    }

    function frame() {
      if (!playing || !ctx) return;

      const now = ctx.currentTime;

      while (marks.length > 1 && marks[1].time <= now) marks.shift();

      const mark = marks[0];

      if (mark && now >= mark.time) {
        const part = parts[mark.part];
        const info = layout[mark.part];
        const pos = mark.step + (now - mark.time) / mark.dur;
        const within = pos % info.runSteps;

        current = mark.part;
        partOut.textContent = String(mark.part + 1);
        barOut.textContent = String(part.startBar + Math.floor(within / part.stepsPerBar));
        bpmOut.textContent = bpmText(part.bpm);

        cards.forEach((card, index) => {
          if (card) card.classList.toggle('active', index === mark.part);
          if (bars[index]) bars[index].style.width = index === mark.part ? percent(Math.min(pos / (info.runSteps * part.repeat), 1)) : '0%';
        });
      }

      frameId = requestAnimationFrame(frame);
    }

    function tick() {
      if (!ctx) return;

      while (!ended && nextTime - ctx.currentTime < LOOKAHEAD) {
        if (latch && latch.part !== nextPart) latch = null;

        if (nextPart < parts.length) {
          const first = parts[nextPart];

          latch = holdRegion(S, latch, nextStep, layout[nextPart].runSteps, first.stepsPerBar, first.stepsPerBeat);

          if (latch) {
            latch.part = nextPart;
            if (nextStep >= latch.end) nextStep = latch.start;
          }
        }

        if (nextPart >= parts.length) {
          if (!looping) {
            ended = true;
            break;
          }

          nextPart = 0;
        }

        const part = parts[nextPart];
        const info = layout[nextPart];
        const dur = part.stepSeconds / speed(S);
        S.fx.beat = dur * part.stepsPerBeat;

        info.byStep[nextStep].forEach(([index, event]) => {
          const voice = part.tracks[index].instrument === 'drums' ? drum : tone;
          voice(part.tracks[index], info.buses[index], event, nextTime, dur);
        });

        marks.push({ time: nextTime, dur, part: nextPart, step: nextStep + nextRepeat * info.runSteps });
        nextTime += dur;
        nextStep++;

        if (nextStep >= info.runSteps) {
          nextStep = 0;
          nextRepeat++;

          if (nextRepeat >= part.repeat) {
            nextRepeat = 0;
            nextPart++;
          }
        }
      }

      applyEchoTime(S);

      if (ended && ctx.currentTime > nextTime) stop();
    }

    async function play(from = 0) {
      if (playing || starting) return;
      starting = true;

      initAudio();
      layout.forEach((info, index) => {
        info.buses = parts[index].tracks.map(() => makeBus(master));
      });
      await ctx.resume();

      starting = false;
      marks = [];
      latch = null;
      ended = false;
      nextPart = from;
      nextRepeat = 0;
      nextStep = 0;
      nextTime = ctx.currentTime + 0.1;
      tick();

      timer = setInterval(tick, 100);
      playing = true;
      renderState();
      frameId = requestAnimationFrame(frame);
    }

    function stop() {
      clearInterval(timer);
      cancelAnimationFrame(frameId);
      closeAudio();
      marks = [];
      playing = false;
      renderState();
    }

    async function jump(index) {
      const target = Math.max(0, Math.min(parts.length - 1, index));

      if (playing) stop();
      await play(target);
    }

    function toggleTransport() {
      if (playing) stop();
      else play(0);
    }

    playButton.addEventListener('click', toggleTransport);
    prevButton.addEventListener('click', () => jump(current - 1));
    nextButton.addEventListener('click', () => jump(current + 1));

    document.addEventListener('keydown', event => {
      if (event.ctrlKey || event.metaKey || event.altKey) return;

      const onBody = event.target === document.body;

      if (!onBody && !(event.target instanceof HTMLButtonElement)) return;

      if (event.code === 'Space' && onBody) {
        event.preventDefault();
        toggleTransport();
        return;
      }

      const number = Number(event.key);

      if (Number.isInteger(number) && number >= 1 && number <= parts.length) {
        jump(number - 1);
      }
    });

    renderState();
  }

  if (mixData) mountMix(JSON.parse(mixData.textContent));
  else if (setData) mountSet(JSON.parse(setData.textContent));
  else mountProject(JSON.parse(projectData.textContent));
})();
