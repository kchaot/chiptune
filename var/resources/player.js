(() => {
  'use strict';

  const root = document.getElementById('daw');
  const projectData = document.getElementById('project');
  const setData = document.getElementById('set');

  if (!root || !(projectData || setData)) return;

  const WAVES = { square: 'square', triangle: 'triangle', saw: 'sawtooth', sine: 'sine' };
  const DRUM_ORDER = ['k', 'c', 's', 'o', 'h'];
  const LOOKAHEAD = 0.6;

  const pulseCache = new Map();
  let ctx = null;
  let master = null;
  let noise = null;
  let rate = 1;

  const fx = { low: 0, mid: 0, high: 0, filter: 0 };
  let fxNodes = null;

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

  function tempoControl(onChange) {
    const slider = el('input');
    slider.type = 'range';
    slider.min = '-16';
    slider.max = '16';
    slider.step = '0.1';
    slider.value = '0';
    slider.setAttribute('aria-label', 'Tempo anpassen, in Prozent');

    const out = el('b', null, '±0.0 %');
    const reset = el('button', 'skip', '1:1');
    reset.type = 'button';
    reset.setAttribute('aria-label', 'Tempo zurücksetzen');

    function apply() {
      const value = Number(slider.value);
      rate = 1 + value / 100;
      out.textContent = (value > 0 ? '+' : value < 0 ? '\u2212' : '\u00b1') + Math.abs(value).toFixed(1) + ' %';
      onChange();
    }

    slider.addEventListener('input', apply);
    reset.addEventListener('click', () => {
      slider.value = '0';
      apply();
    });

    const box = el('div', 'pitch');
    box.append(el('span', 'pitch-name', 'Tempo'), slider, out, reset);

    return box;
  }

  const MIXER = [
    { key: 'low', name: 'Tief', min: -12, max: 12, step: 0.5 },
    { key: 'mid', name: 'Mitte', min: -12, max: 12, step: 0.5 },
    { key: 'high', name: 'Hoch', min: -12, max: 12, step: 0.5 },
    { key: 'filter', name: 'Filter', min: -100, max: 100, step: 1 },
  ];

  function mixerText(key, value) {
    if (key === 'filter') {
      if (value === 0) return 'aus';
      return (value < 0 ? 'Tiefpass ' : 'Hochpass ') + Math.abs(value) + ' %';
    }

    return (value > 0 ? '+' : value < 0 ? '\u2212' : '\u00b1') + Math.abs(value).toFixed(1) + ' dB';
  }

  // EQ und DJ-Filter für den ganzen Mix. Doppelklick auf einen Regler setzt ihn zurück.
  function mixerControl() {
    const box = el('div', 'mixer');
    const sliders = [];

    function show(entry, slider, out) {
      const value = Number(slider.value);
      out.textContent = mixerText(entry.key, value);
      fx[entry.key] = entry.key === 'filter' ? value / 100 : value;
      applyFx(false);
    }

    MIXER.forEach(entry => {
      const slider = el('input');
      slider.type = 'range';
      slider.min = String(entry.min);
      slider.max = String(entry.max);
      slider.step = String(entry.step);
      slider.value = '0';
      slider.setAttribute('aria-label', entry.name + (entry.key === 'filter' ? ' (links Tiefpass, rechts Hochpass)' : ' in dB'));

      const out = el('b', null, mixerText(entry.key, 0));
      slider.addEventListener('input', () => show(entry, slider, out));
      slider.addEventListener('dblclick', () => {
        slider.value = '0';
        show(entry, slider, out);
      });

      const group = el('label', 'knob');
      group.append(el('span', 'pitch-name', entry.name), slider, out);
      box.append(group);
      sliders.push([entry, slider, out]);
    });

    const reset = el('button', 'skip', 'Reset');
    reset.type = 'button';
    reset.setAttribute('aria-label', 'EQ und Filter zurücksetzen');
    reset.addEventListener('click', () => sliders.forEach(([entry, slider, out]) => {
      slider.value = '0';
      show(entry, slider, out);
    }));
    box.append(reset);

    return box;
  }

  function bpmText(bpm) {
    return String(Number((bpm * rate).toFixed(1)));
  }

  if (setData) mountSet(JSON.parse(setData.textContent));
  else mountProject(JSON.parse(projectData.textContent));

  function initAudio() {
    ctx = new (window.AudioContext || window.webkitAudioContext)();
    master = ctx.createGain();
    master.gain.value = 0.8;

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
    const limiter = ctx.createDynamicsCompressor();
    limiter.threshold.value = -2;
    limiter.knee.value = 3;
    limiter.ratio.value = 20;
    limiter.attack.value = 0.003;
    limiter.release.value = 0.1;

    master.connect(low);
    low.connect(mid);
    mid.connect(high);
    high.connect(sweep);
    sweep.connect(limiter);
    limiter.connect(ctx.destination);

    fxNodes = { low, mid, high, sweep };
    applyFx(true);

    noise = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
    const samples = noise.getChannelData(0);
    for (let i = 0; i < samples.length; i++) samples[i] = Math.random() * 2 - 1;

    pulseCache.clear();
  }

  function closeAudio() {
    if (ctx) ctx.close();
    ctx = null;
    fxNodes = null;
  }

  function applyFx(instant) {
    if (!ctx || !fxNodes) return;

    const now = ctx.currentTime;
    const set = (param, value) => {
      if (instant) param.value = value;
      else param.setTargetAtTime(value, now, 0.02);
    };

    set(fxNodes.low.gain, fx.low);
    set(fxNodes.mid.gain, fx.mid);
    set(fxNodes.high.gain, fx.high);

    const amount = fx.filter;
    const sweep = fxNodes.sweep;

    sweep.type = amount > 0 ? 'highpass' : 'lowpass';
    set(sweep.frequency, amount > 0 ? 20 * Math.pow(250, amount) : 20000 * Math.pow(0.0125, -amount));
    set(sweep.Q, amount === 0 ? 0.7 : 2.5);
  }

  function makeBus() {
    const bus = ctx.createGain();
    bus.connect(master);
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


  function mountProject(project) {
    const tracks = project.tracks;
    const bars = project.steps / project.stepsPerBar;

    let buses = [];
    let timer = null;
    let frameId = 0;
    let nextTime = 0;
    let nextStep = 0;
    let ended = false;
    let marks = [];
    let playing = false;
    let starting = false;

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

    const bpmOut = el('b', null, bpmText(project.bpm));
    const tempo = el('span', 'tempo');
    tempo.append(bpmOut, document.createTextNode(' BPM'));

    const transport = el('div', 'transport');
    transport.append(playButton, position, tempo, tempoControl(() => {
      bpmOut.textContent = bpmText(project.bpm);
    }));

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

    const narrow = typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 44rem)').matches;
    const labelStep = narrow && bars > 8 ? 4 : 1;

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
      if (track.group && (!currentGroup || currentGroup.title !== track.group.title || currentGroup.href !== track.group.href)) {
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

      if (track.notes) {
        nameElement = el('button', 'name has-notes', track.name);
        nameElement.type = 'button';
        nameElement.title = 'Beschreibung anzeigen';
        nameElement.addEventListener('click', () => showTrackNotes(index));
      } else {
        nameElement = el('span', 'name', track.name);
      }

      label.append(nameElement, el('span', 'instrument', instrumentLabel(track)));

      const refs = track.refs || [];

      if (refs.length > 0 && !track.group) {
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

    root.replaceChildren(transport, mixerControl(), arrangement);
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

    function toggle(list, index) {
      list[index] = !list[index];
      applyMix();
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

    function renderButton() {
      playButton.textContent = playing ? 'Stop' : 'Play';
      playButton.classList.toggle('on', playing);
      playhead.hidden = !playing;

      if (!playing) {
        barOut.textContent = '1';
        beatOut.textContent = '1';
      }
    }

    function frame() {
      if (!playing || !ctx) return;

      const now = ctx.currentTime;

      while (marks.length > 1 && marks[1].time <= now) marks.shift();

      const mark = marks[0];

      if (mark && now >= mark.time) {
        const step = Math.min(mark.step + (now - mark.time) / mark.dur, project.steps - 1e-6);

        playhead.style.left = percent(step / project.steps);
        barOut.textContent = String(Math.floor(step / project.stepsPerBar) + 1);
        beatOut.textContent = String(Math.floor((step % project.stepsPerBar) / project.stepsPerBeat) + 1);
      }

      frameId = requestAnimationFrame(frame);
    }

    function tick() {
      if (!ctx) return;

      while (!ended && nextTime - ctx.currentTime < LOOKAHEAD) {
        if (nextStep >= project.steps) {
          if (!project.loop) {
            ended = true;
            break;
          }

          nextStep = 0;
        }

        const dur = project.stepSeconds / rate;

        byStep[nextStep].forEach(([index, event]) => {
          const voice = tracks[index].instrument === 'drums' ? drum : tone;
          voice(tracks[index], buses[index], event, nextTime, dur);
        });

        marks.push({ time: nextTime, dur, step: nextStep });
        nextTime += dur;
        nextStep++;
      }

      if (ended && ctx.currentTime > nextTime) stop();
    }

    async function play() {
      if (playing || starting) return;
      starting = true;

      initAudio();
      buses = tracks.map(makeBus);
      await ctx.resume();

      starting = false;
      applyMix();

      marks = [];
      ended = false;
      nextStep = 0;
      nextTime = ctx.currentTime + 0.1;
      tick();

      timer = setInterval(tick, 100);
      playing = true;
      renderButton();
      frameId = requestAnimationFrame(frame);
    }

    function stop() {
      clearInterval(timer);
      cancelAnimationFrame(frameId);

      closeAudio();
      buses = [];
      marks = [];
      playing = false;
      renderButton();
    }

    function toggleTransport() {
      if (playing) stop();
      else play();
    }

    playButton.addEventListener('click', toggleTransport);

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
    transport.append(playButton, prevButton, nextButton, loopButton, position, tempo, tempoControl(() => {
      bpmOut.textContent = bpmText(parts[current].bpm);
    }));
    root.replaceChildren(transport, mixerControl());

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
        if (nextPart >= parts.length) {
          if (!looping) {
            ended = true;
            break;
          }

          nextPart = 0;
        }

        const part = parts[nextPart];
        const info = layout[nextPart];
        const dur = part.stepSeconds / rate;

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

      if (ended && ctx.currentTime > nextTime) stop();
    }

    async function play(from = 0) {
      if (playing || starting) return;
      starting = true;

      initAudio();
      layout.forEach((info, index) => {
        info.buses = parts[index].tracks.map(makeBus);
      });
      await ctx.resume();

      starting = false;
      marks = [];
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
})();
