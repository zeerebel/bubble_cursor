/*!
 * Bubble Cursor — Fluid (smoke) layer
 * A self-contained WebGL fluid simulation that paints a colourful "smoke"
 * trail under the mouse pointer. This is a trimmed, dependency-free port of
 * Pavel Dobryakov's WebGL-Fluid-Simulation (MIT License),
 * https://github.com/PavelDoGreat/WebGL-Fluid-Simulation — the same engine
 * the TreeThemes "Deep" theme ships as smokey-fluid-cursor.min.js.
 *
 * Exposes: window.BubbleCursorFluid.start(canvas, config) / .stop()
 */
(function (window, document) {
  'use strict';

  var instance = null;

  var DEFAULTS = {
    SIM_RESOLUTION: 128,
    DYE_RESOLUTION: 1024,
    CAPTURE_RESOLUTION: 512,
    DENSITY_DISSIPATION: 0.98,
    VELOCITY_DISSIPATION: 0.98,
    PRESSURE: 0.8,
    PRESSURE_ITERATIONS: 20,
    CURL: 30,
    SPLAT_RADIUS: 0.25,
    SPLAT_FORCE: 6000,
    SHADING: true,
    COLORFUL: true,
    INTENSITY: 1,
    COLOR_UPDATE_SPEED: 10,
    PAUSED: false,
    BACK_COLOR: { r: 0, g: 0, b: 0 },
    TRANSPARENT: true,
    BLOOM: true,
    BLOOM_ITERATIONS: 8,
    BLOOM_RESOLUTION: 256,
    BLOOM_INTENSITY: 0.8,
    BLOOM_THRESHOLD: 0.6,
    BLOOM_SOFT_KNEE: 0.7,
    SUNRAYS: false,
    MAX_DPR: 2
  };

  function FluidSim(canvas, userConfig) {
    var config = {};
    var k;
    for (k in DEFAULTS) { if (DEFAULTS.hasOwnProperty(k)) config[k] = DEFAULTS[k]; }
    if (userConfig) { for (k in userConfig) { if (userConfig.hasOwnProperty(k) && userConfig[k] !== undefined && userConfig[k] !== null) config[k] = userConfig[k]; } }

    this.canvas = canvas;
    this.config = config;
    this.running = false;
    this._raf = null;
    this._listeners = [];

    var ctx = getWebGLContext(canvas);
    if (!ctx) { this.unsupported = true; return; }
    this.gl = ctx.gl;
    this.ext = ctx.ext;

    if (!this.ext.supportLinearFiltering) {
      config.DYE_RESOLUTION = 512;
      config.SHADING = false;
      config.BLOOM = false;
    }

    this.pointers = [createPointer()];
    this.lastColorTime = 0;
    this.colorUpdateTimer = 0;
    this.lastUpdate = now();

    this._initShaders();
    this._initBlit();
    this.resize();
    this.initFramebuffers();
    this._bindEvents();
  }

  /* ----------------------------------------------------------------- *
   * WebGL context + extensions
   * ----------------------------------------------------------------- */
  function getWebGLContext(canvas) {
    var params = { alpha: true, depth: false, stencil: false, antialias: false, preserveDrawingBuffer: false };
    var gl = canvas.getContext('webgl2', params);
    var isWebGL2 = !!gl;
    if (!isWebGL2) {
      gl = canvas.getContext('webgl', params) || canvas.getContext('experimental-webgl', params);
    }
    if (!gl) return null;

    var halfFloat, supportLinearFiltering;
    if (isWebGL2) {
      gl.getExtension('EXT_color_buffer_float');
      supportLinearFiltering = gl.getExtension('OES_texture_float_linear');
    } else {
      halfFloat = gl.getExtension('OES_texture_half_float');
      supportLinearFiltering = gl.getExtension('OES_texture_half_float_linear');
    }
    gl.clearColor(0.0, 0.0, 0.0, 1.0);

    var halfFloatTexType = isWebGL2 ? gl.HALF_FLOAT : (halfFloat && halfFloat.HALF_FLOAT_OES);
    var formatRGBA, formatRG, formatR;

    if (isWebGL2) {
      formatRGBA = getSupportedFormat(gl, gl.RGBA16F, gl.RGBA, halfFloatTexType);
      formatRG = getSupportedFormat(gl, gl.RG16F, gl.RG, halfFloatTexType);
      formatR = getSupportedFormat(gl, gl.R16F, gl.RED, halfFloatTexType);
    } else {
      formatRGBA = getSupportedFormat(gl, gl.RGBA, gl.RGBA, halfFloatTexType);
      formatRG = formatRGBA;
      formatR = formatRGBA;
    }

    if (!formatRGBA) return null;

    return {
      gl: gl,
      ext: {
        formatRGBA: formatRGBA,
        formatRG: formatRG,
        formatR: formatR,
        halfFloatTexType: halfFloatTexType,
        supportLinearFiltering: supportLinearFiltering
      }
    };
  }

  function getSupportedFormat(gl, internalFormat, format, type) {
    if (!supportRenderTextureFormat(gl, internalFormat, format, type)) {
      switch (internalFormat) {
        case gl.R16F: return getSupportedFormat(gl, gl.RG16F, gl.RG, type);
        case gl.RG16F: return getSupportedFormat(gl, gl.RGBA16F, gl.RGBA, type);
        default: return null;
      }
    }
    return { internalFormat: internalFormat, format: format };
  }

  function supportRenderTextureFormat(gl, internalFormat, format, type) {
    var texture = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, texture);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.NEAREST);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.NEAREST);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    gl.texImage2D(gl.TEXTURE_2D, 0, internalFormat, 4, 4, 0, format, type, null);
    var fbo = gl.createFramebuffer();
    gl.bindFramebuffer(gl.FRAMEBUFFER, fbo);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, texture, 0);
    var status = gl.checkFramebufferStatus(gl.FRAMEBUFFER);
    return status === gl.FRAMEBUFFER_COMPLETE;
  }

  /* ----------------------------------------------------------------- *
   * Shader helpers
   * ----------------------------------------------------------------- */
  function compileShader(gl, type, source, keywords) {
    source = addKeywords(source, keywords);
    var shader = gl.createShader(type);
    gl.shaderSource(shader, source);
    gl.compileShader(shader);
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
      // eslint-disable-next-line no-console
      console.warn('[BubbleCursor] shader compile error:', gl.getShaderInfoLog(shader));
    }
    return shader;
  }

  function addKeywords(source, keywords) {
    if (!keywords) return source;
    var keywordsString = '';
    keywords.forEach(function (keyword) { keywordsString += '#define ' + keyword + '\n'; });
    return keywordsString + source;
  }

  function createProgram(gl, vertexShader, fragmentShader) {
    var program = gl.createProgram();
    gl.attachShader(program, vertexShader);
    gl.attachShader(program, fragmentShader);
    gl.linkProgram(program);
    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
      // eslint-disable-next-line no-console
      console.warn('[BubbleCursor] program link error:', gl.getProgramInfoLog(program));
    }
    return program;
  }

  function getUniforms(gl, program) {
    var uniforms = {};
    var count = gl.getProgramParameter(program, gl.ACTIVE_UNIFORMS);
    for (var i = 0; i < count; i++) {
      var name = gl.getActiveUniform(program, i).name;
      uniforms[name] = gl.getUniformLocation(program, name);
    }
    return uniforms;
  }

  function Program(gl, vertexShader, fragmentShader) {
    this.gl = gl;
    this.program = createProgram(gl, vertexShader, fragmentShader);
    this.uniforms = getUniforms(gl, this.program);
  }
  Program.prototype.bind = function () { this.gl.useProgram(this.program); };

  function Material(gl, vertexShader, fragmentShaderSource) {
    this.gl = gl;
    this.vertexShader = vertexShader;
    this.fragmentShaderSource = fragmentShaderSource;
    this.programs = {};
    this.activeProgram = null;
    this.uniforms = {};
  }
  Material.prototype.setKeywords = function (keywords) {
    var hash = 0;
    for (var i = 0; i < keywords.length; i++) hash += hashCode(keywords[i]);
    var program = this.programs[hash];
    if (program == null) {
      var fragmentShader = compileShader(this.gl, this.gl.FRAGMENT_SHADER, this.fragmentShaderSource, keywords);
      program = createProgram(this.gl, this.vertexShader, fragmentShader);
      this.programs[hash] = program;
    }
    if (program === this.activeProgram) return;
    this.uniforms = getUniforms(this.gl, program);
    this.activeProgram = program;
  };
  Material.prototype.bind = function () { this.gl.useProgram(this.activeProgram); };

  function hashCode(s) {
    if (s.length === 0) return 0;
    var hash = 0;
    for (var i = 0; i < s.length; i++) {
      hash = (hash << 5) - hash + s.charCodeAt(i);
      hash |= 0;
    }
    return hash;
  }

  /* ----------------------------------------------------------------- *
   * Shaders
   * ----------------------------------------------------------------- */
  var baseVertexShaderSource = [
    'precision highp float;',
    'attribute vec2 aPosition;',
    'varying vec2 vUv;',
    'varying vec2 vL;',
    'varying vec2 vR;',
    'varying vec2 vT;',
    'varying vec2 vB;',
    'uniform vec2 texelSize;',
    'void main () {',
    '  vUv = aPosition * 0.5 + 0.5;',
    '  vL = vUv - vec2(texelSize.x, 0.0);',
    '  vR = vUv + vec2(texelSize.x, 0.0);',
    '  vT = vUv + vec2(0.0, texelSize.y);',
    '  vB = vUv - vec2(0.0, texelSize.y);',
    '  gl_Position = vec4(aPosition, 0.0, 1.0);',
    '}'
  ].join('\n');

  var copyShaderSource = [
    'precision mediump float;',
    'precision mediump sampler2D;',
    'varying highp vec2 vUv;',
    'uniform sampler2D uTexture;',
    'void main () { gl_FragColor = texture2D(uTexture, vUv); }'
  ].join('\n');

  var clearShaderSource = [
    'precision mediump float;',
    'precision mediump sampler2D;',
    'varying highp vec2 vUv;',
    'uniform sampler2D uTexture;',
    'uniform float value;',
    'void main () { gl_FragColor = value * texture2D(uTexture, vUv); }'
  ].join('\n');

  var displayShaderSource = [
    'precision highp float;',
    'precision highp sampler2D;',
    'varying vec2 vUv;',
    'varying vec2 vL;',
    'varying vec2 vR;',
    'varying vec2 vT;',
    'varying vec2 vB;',
    'uniform sampler2D uTexture;',
    'uniform sampler2D uBloom;',
    'uniform vec2 texelSize;',
    'vec3 linearToGamma (vec3 color) {',
    '  color = max(color, vec3(0.0));',
    '  return max(1.055 * pow(color, vec3(0.416666667)) - 0.055, vec3(0.0));',
    '}',
    'void main () {',
    '  vec3 c = texture2D(uTexture, vUv).rgb;',
    '#ifdef SHADING',
    '  vec3 lc = texture2D(uTexture, vL).rgb;',
    '  vec3 rc = texture2D(uTexture, vR).rgb;',
    '  vec3 tc = texture2D(uTexture, vT).rgb;',
    '  vec3 bc = texture2D(uTexture, vB).rgb;',
    '  float dx = length(rc) - length(lc);',
    '  float dy = length(tc) - length(bc);',
    '  vec3 n = normalize(vec3(dx, dy, length(texelSize)));',
    '  vec3 l = vec3(0.0, 0.0, 1.0);',
    '  float diffuse = clamp(dot(n, l) + 0.7, 0.7, 1.0);',
    '  c *= diffuse;',
    '#endif',
    '#ifdef BLOOM',
    '  vec3 bloom = texture2D(uBloom, vUv).rgb;',
    '  float noise = 0.0;',
    '  bloom = linearToGamma(bloom);',
    '  c += bloom;',
    '#endif',
    '  float a = max(c.r, max(c.g, c.b));',
    '  gl_FragColor = vec4(c, a);',
    '}'
  ].join('\n');

  var bloomPrefilterShaderSource = [
    'precision mediump float;',
    'precision mediump sampler2D;',
    'varying vec2 vUv;',
    'uniform sampler2D uTexture;',
    'uniform vec3 curve;',
    'uniform float threshold;',
    'void main () {',
    '  vec3 c = texture2D(uTexture, vUv).rgb;',
    '  float br = max(c.r, max(c.g, c.b));',
    '  float rq = clamp(br - curve.x, 0.0, curve.y);',
    '  rq = curve.z * rq * rq;',
    '  c *= max(rq, br - threshold) / max(br, 0.0001);',
    '  gl_FragColor = vec4(c, 0.0);',
    '}'
  ].join('\n');

  var bloomBlurShaderSource = [
    'precision mediump float;',
    'precision mediump sampler2D;',
    'varying vec2 vL;',
    'varying vec2 vR;',
    'varying vec2 vT;',
    'varying vec2 vB;',
    'uniform sampler2D uTexture;',
    'void main () {',
    '  vec4 sum = vec4(0.0);',
    '  sum += texture2D(uTexture, vL);',
    '  sum += texture2D(uTexture, vR);',
    '  sum += texture2D(uTexture, vT);',
    '  sum += texture2D(uTexture, vB);',
    '  sum *= 0.25;',
    '  gl_FragColor = sum;',
    '}'
  ].join('\n');

  var bloomFinalShaderSource = [
    'precision mediump float;',
    'precision mediump sampler2D;',
    'varying vec2 vL;',
    'varying vec2 vR;',
    'varying vec2 vT;',
    'varying vec2 vB;',
    'uniform sampler2D uTexture;',
    'uniform float intensity;',
    'void main () {',
    '  vec4 sum = vec4(0.0);',
    '  sum += texture2D(uTexture, vL);',
    '  sum += texture2D(uTexture, vR);',
    '  sum += texture2D(uTexture, vT);',
    '  sum += texture2D(uTexture, vB);',
    '  sum *= 0.25;',
    '  gl_FragColor = sum * intensity;',
    '}'
  ].join('\n');

  var splatShaderSource = [
    'precision highp float;',
    'precision highp sampler2D;',
    'varying vec2 vUv;',
    'uniform sampler2D uTarget;',
    'uniform float aspectRatio;',
    'uniform vec3 color;',
    'uniform vec2 point;',
    'uniform float radius;',
    'void main () {',
    '  vec2 p = vUv - point.xy;',
    '  p.x *= aspectRatio;',
    '  vec3 splat = exp(-dot(p, p) / radius) * color;',
    '  vec3 base = texture2D(uTarget, vUv).xyz;',
    '  gl_FragColor = vec4(base + splat, 1.0);',
    '}'
  ].join('\n');

  var advectionShaderSource = [
    'precision highp float;',
    'precision highp sampler2D;',
    'varying vec2 vUv;',
    'uniform sampler2D uVelocity;',
    'uniform sampler2D uSource;',
    'uniform vec2 texelSize;',
    'uniform vec2 dyeTexelSize;',
    'uniform float dt;',
    'uniform float dissipation;',
    'vec4 bilerp (sampler2D sam, vec2 uv, vec2 tsize) {',
    '  vec2 st = uv / tsize - 0.5;',
    '  vec2 iuv = floor(st);',
    '  vec2 fuv = fract(st);',
    '  vec4 a = texture2D(sam, (iuv + vec2(0.5, 0.5)) * tsize);',
    '  vec4 b = texture2D(sam, (iuv + vec2(1.5, 0.5)) * tsize);',
    '  vec4 c = texture2D(sam, (iuv + vec2(0.5, 1.5)) * tsize);',
    '  vec4 d = texture2D(sam, (iuv + vec2(1.5, 1.5)) * tsize);',
    '  return mix(mix(a, b, fuv.x), mix(c, d, fuv.x), fuv.y);',
    '}',
    'void main () {',
    '#ifdef MANUAL_FILTERING',
    '  vec2 coord = vUv - dt * bilerp(uVelocity, vUv, texelSize).xy * texelSize;',
    '  vec4 result = bilerp(uSource, coord, dyeTexelSize);',
    '#else',
    '  vec2 coord = vUv - dt * texture2D(uVelocity, vUv).xy * texelSize;',
    '  vec4 result = texture2D(uSource, coord);',
    '#endif',
    '  float decay = 1.0 + dissipation * dt;',
    '  gl_FragColor = result / decay;',
    '}'
  ].join('\n');

  var divergenceShaderSource = [
    'precision mediump float;',
    'precision mediump sampler2D;',
    'varying highp vec2 vUv;',
    'varying highp vec2 vL;',
    'varying highp vec2 vR;',
    'varying highp vec2 vT;',
    'varying highp vec2 vB;',
    'uniform sampler2D uVelocity;',
    'void main () {',
    '  float L = texture2D(uVelocity, vL).x;',
    '  float R = texture2D(uVelocity, vR).x;',
    '  float T = texture2D(uVelocity, vT).y;',
    '  float B = texture2D(uVelocity, vB).y;',
    '  vec2 C = texture2D(uVelocity, vUv).xy;',
    '  if (vL.x < 0.0) { L = -C.x; }',
    '  if (vR.x > 1.0) { R = -C.x; }',
    '  if (vT.y > 1.0) { T = -C.y; }',
    '  if (vB.y < 0.0) { B = -C.y; }',
    '  float div = 0.5 * (R - L + T - B);',
    '  gl_FragColor = vec4(div, 0.0, 0.0, 1.0);',
    '}'
  ].join('\n');

  var curlShaderSource = [
    'precision mediump float;',
    'precision mediump sampler2D;',
    'varying highp vec2 vUv;',
    'varying highp vec2 vL;',
    'varying highp vec2 vR;',
    'varying highp vec2 vT;',
    'varying highp vec2 vB;',
    'uniform sampler2D uVelocity;',
    'void main () {',
    '  float L = texture2D(uVelocity, vL).y;',
    '  float R = texture2D(uVelocity, vR).y;',
    '  float T = texture2D(uVelocity, vT).x;',
    '  float B = texture2D(uVelocity, vB).x;',
    '  float vorticity = R - L - T + B;',
    '  gl_FragColor = vec4(0.5 * vorticity, 0.0, 0.0, 1.0);',
    '}'
  ].join('\n');

  var vorticityShaderSource = [
    'precision highp float;',
    'precision highp sampler2D;',
    'varying vec2 vUv;',
    'varying vec2 vL;',
    'varying vec2 vR;',
    'varying vec2 vT;',
    'varying vec2 vB;',
    'uniform sampler2D uVelocity;',
    'uniform sampler2D uCurl;',
    'uniform float curl;',
    'uniform float dt;',
    'void main () {',
    '  float L = texture2D(uCurl, vL).x;',
    '  float R = texture2D(uCurl, vR).x;',
    '  float T = texture2D(uCurl, vT).x;',
    '  float B = texture2D(uCurl, vB).x;',
    '  float C = texture2D(uCurl, vUv).x;',
    '  vec2 force = 0.5 * vec2(abs(T) - abs(B), abs(R) - abs(L));',
    '  force /= length(force) + 0.0001;',
    '  force *= curl * C;',
    '  force.y *= -1.0;',
    '  vec2 velocity = texture2D(uVelocity, vUv).xy;',
    '  velocity += force * dt;',
    '  velocity = min(max(velocity, -1000.0), 1000.0);',
    '  gl_FragColor = vec4(velocity, 0.0, 1.0);',
    '}'
  ].join('\n');

  var pressureShaderSource = [
    'precision mediump float;',
    'precision mediump sampler2D;',
    'varying highp vec2 vUv;',
    'varying highp vec2 vL;',
    'varying highp vec2 vR;',
    'varying highp vec2 vT;',
    'varying highp vec2 vB;',
    'uniform sampler2D uPressure;',
    'uniform sampler2D uDivergence;',
    'void main () {',
    '  float L = texture2D(uPressure, vL).x;',
    '  float R = texture2D(uPressure, vR).x;',
    '  float T = texture2D(uPressure, vT).x;',
    '  float B = texture2D(uPressure, vB).x;',
    '  float C = texture2D(uPressure, vUv).x;',
    '  float divergence = texture2D(uDivergence, vUv).x;',
    '  float pressure = (L + R + B + T - divergence) * 0.25;',
    '  gl_FragColor = vec4(pressure, 0.0, 0.0, 1.0);',
    '}'
  ].join('\n');

  var gradientSubtractShaderSource = [
    'precision mediump float;',
    'precision mediump sampler2D;',
    'varying highp vec2 vUv;',
    'varying highp vec2 vL;',
    'varying highp vec2 vR;',
    'varying highp vec2 vT;',
    'varying highp vec2 vB;',
    'uniform sampler2D uPressure;',
    'uniform sampler2D uVelocity;',
    'void main () {',
    '  float L = texture2D(uPressure, vL).x;',
    '  float R = texture2D(uPressure, vR).x;',
    '  float T = texture2D(uPressure, vT).x;',
    '  float B = texture2D(uPressure, vB).x;',
    '  vec2 velocity = texture2D(uVelocity, vUv).xy;',
    '  velocity.xy -= vec2(R - L, T - B);',
    '  gl_FragColor = vec4(velocity, 0.0, 1.0);',
    '}'
  ].join('\n');

  /* ----------------------------------------------------------------- *
   * FBO helpers
   * ----------------------------------------------------------------- */
  FluidSim.prototype._initBlit = function () {
    var gl = this.gl;
    this._quadBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, this._quadBuffer);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, -1, 1, 1, 1, 1, -1]), gl.STATIC_DRAW);
    this._elemBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, this._elemBuffer);
    gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, new Uint16Array([0, 1, 2, 0, 2, 3]), gl.STATIC_DRAW);
    gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);
    gl.enableVertexAttribArray(0);
  };

  FluidSim.prototype._blit = function (target, clear) {
    var gl = this.gl;
    if (target == null) {
      gl.viewport(0, 0, gl.drawingBufferWidth, gl.drawingBufferHeight);
      gl.bindFramebuffer(gl.FRAMEBUFFER, null);
    } else {
      gl.viewport(0, 0, target.width, target.height);
      gl.bindFramebuffer(gl.FRAMEBUFFER, target.fbo);
    }
    if (clear) {
      gl.clearColor(0.0, 0.0, 0.0, 1.0);
      gl.clear(gl.COLOR_BUFFER_BIT);
    }
    gl.drawElements(gl.TRIANGLES, 6, gl.UNSIGNED_SHORT, 0);
  };

  FluidSim.prototype.createFBO = function (w, h, internalFormat, format, type, param) {
    var gl = this.gl;
    gl.activeTexture(gl.TEXTURE0);
    var texture = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, texture);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, param);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, param);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    gl.texImage2D(gl.TEXTURE_2D, 0, internalFormat, w, h, 0, format, type, null);

    var fbo = gl.createFramebuffer();
    gl.bindFramebuffer(gl.FRAMEBUFFER, fbo);
    gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, texture, 0);
    gl.viewport(0, 0, w, h);
    gl.clear(gl.COLOR_BUFFER_BIT);

    var texelSizeX = 1.0 / w;
    var texelSizeY = 1.0 / h;
    return {
      texture: texture, fbo: fbo, width: w, height: h,
      texelSizeX: texelSizeX, texelSizeY: texelSizeY,
      attach: function (id) {
        gl.activeTexture(gl.TEXTURE0 + id);
        gl.bindTexture(gl.TEXTURE_2D, texture);
        return id;
      },
      // Release the GPU texture + framebuffer so re-creating on resize does not leak.
      destroy: function () {
        if (texture) { gl.deleteTexture(texture); texture = null; }
        if (fbo) { gl.deleteFramebuffer(fbo); fbo = null; }
      }
    };
  };

  FluidSim.prototype.createDoubleFBO = function (w, h, internalFormat, format, type, param) {
    var fbo1 = this.createFBO(w, h, internalFormat, format, type, param);
    var fbo2 = this.createFBO(w, h, internalFormat, format, type, param);
    return {
      width: w, height: h, texelSizeX: fbo1.texelSizeX, texelSizeY: fbo1.texelSizeY,
      get read() { return fbo1; },
      set read(value) { fbo1 = value; },
      get write() { return fbo2; },
      set write(value) { fbo2 = value; },
      swap: function () { var temp = fbo1; fbo1 = fbo2; fbo2 = temp; }
    };
  };

  FluidSim.prototype.resizeFBO = function (target, w, h, internalFormat, format, type, param) {
    var copy = this.copyProgram;
    var newFBO = this.createFBO(w, h, internalFormat, format, type, param);
    copy.bind();
    this.gl.uniform1i(copy.uniforms.uTexture, target.attach(0));
    this._blit(newFBO);
    if (target && target.destroy) target.destroy(); // free the old buffer we just copied from
    return newFBO;
  };

  FluidSim.prototype.resizeDoubleFBO = function (target, w, h, internalFormat, format, type, param) {
    if (target.width === w && target.height === h) return target;
    target.read = this.resizeFBO(target.read, w, h, internalFormat, format, type, param);
    if (target.write && target.write.destroy) target.write.destroy();
    target.write = this.createFBO(w, h, internalFormat, format, type, param);
    target.width = w;
    target.height = h;
    target.texelSizeX = 1.0 / w;
    target.texelSizeY = 1.0 / h;
    return target;
  };

  /* ----------------------------------------------------------------- *
   * Programs
   * ----------------------------------------------------------------- */
  FluidSim.prototype._initShaders = function () {
    var gl = this.gl;
    var baseVertexShader = compileShader(gl, gl.VERTEX_SHADER, baseVertexShaderSource);

    this.copyProgram = new Program(gl, baseVertexShader, compileShader(gl, gl.FRAGMENT_SHADER, copyShaderSource));
    this.clearProgram = new Program(gl, baseVertexShader, compileShader(gl, gl.FRAGMENT_SHADER, clearShaderSource));
    this.splatProgram = new Program(gl, baseVertexShader, compileShader(gl, gl.FRAGMENT_SHADER, splatShaderSource));
    this.advectionProgram = new Program(gl, baseVertexShader, compileShader(gl, gl.FRAGMENT_SHADER, advectionShaderSource, this.ext.supportLinearFiltering ? null : ['MANUAL_FILTERING']));
    this.divergenceProgram = new Program(gl, baseVertexShader, compileShader(gl, gl.FRAGMENT_SHADER, divergenceShaderSource));
    this.curlProgram = new Program(gl, baseVertexShader, compileShader(gl, gl.FRAGMENT_SHADER, curlShaderSource));
    this.vorticityProgram = new Program(gl, baseVertexShader, compileShader(gl, gl.FRAGMENT_SHADER, vorticityShaderSource));
    this.pressureProgram = new Program(gl, baseVertexShader, compileShader(gl, gl.FRAGMENT_SHADER, pressureShaderSource));
    this.gradienSubtractProgram = new Program(gl, baseVertexShader, compileShader(gl, gl.FRAGMENT_SHADER, gradientSubtractShaderSource));
    this.bloomPrefilterProgram = new Program(gl, baseVertexShader, compileShader(gl, gl.FRAGMENT_SHADER, bloomPrefilterShaderSource));
    this.bloomBlurProgram = new Program(gl, baseVertexShader, compileShader(gl, gl.FRAGMENT_SHADER, bloomBlurShaderSource));
    this.bloomFinalProgram = new Program(gl, baseVertexShader, compileShader(gl, gl.FRAGMENT_SHADER, bloomFinalShaderSource));
    this.displayMaterial = new Material(gl, baseVertexShader, displayShaderSource);
  };

  FluidSim.prototype.updateKeywords = function () {
    var displayKeywords = [];
    if (this.config.SHADING) displayKeywords.push('SHADING');
    if (this.config.BLOOM) displayKeywords.push('BLOOM');
    this.displayMaterial.setKeywords(displayKeywords);
  };

  /* ----------------------------------------------------------------- *
   * Framebuffers
   * ----------------------------------------------------------------- */
  FluidSim.prototype.initFramebuffers = function () {
    var gl = this.gl, ext = this.ext, config = this.config;
    var simRes = getResolution(gl, config.SIM_RESOLUTION);
    var dyeRes = getResolution(gl, config.DYE_RESOLUTION);
    var texType = ext.halfFloatTexType;
    var rgba = ext.formatRGBA, rg = ext.formatRG, r = ext.formatR;
    var filtering = ext.supportLinearFiltering ? gl.LINEAR : gl.NEAREST;
    gl.disable(gl.BLEND);

    if (!this.dye) this.dye = this.createDoubleFBO(dyeRes.width, dyeRes.height, rgba.internalFormat, rgba.format, texType, filtering);
    else this.dye = this.resizeDoubleFBO(this.dye, dyeRes.width, dyeRes.height, rgba.internalFormat, rgba.format, texType, filtering);

    if (!this.velocity) this.velocity = this.createDoubleFBO(simRes.width, simRes.height, rg.internalFormat, rg.format, texType, filtering);
    else this.velocity = this.resizeDoubleFBO(this.velocity, simRes.width, simRes.height, rg.internalFormat, rg.format, texType, filtering);

    // Free single-use buffers from a previous init (resize) before re-allocating.
    if (this.divergence && this.divergence.destroy) this.divergence.destroy();
    if (this.curl && this.curl.destroy) this.curl.destroy();
    if (this.pressure) {
      if (this.pressure.read && this.pressure.read.destroy) this.pressure.read.destroy();
      if (this.pressure.write && this.pressure.write.destroy) this.pressure.write.destroy();
    }

    this.divergence = this.createFBO(simRes.width, simRes.height, r.internalFormat, r.format, texType, gl.NEAREST);
    this.curl = this.createFBO(simRes.width, simRes.height, r.internalFormat, r.format, texType, gl.NEAREST);
    this.pressure = this.createDoubleFBO(simRes.width, simRes.height, r.internalFormat, r.format, texType, gl.NEAREST);

    this.initBloomFramebuffers();
    this.updateKeywords();
  };

  FluidSim.prototype.initBloomFramebuffers = function () {
    var gl = this.gl, ext = this.ext, config = this.config;
    var res = getResolution(gl, config.BLOOM_RESOLUTION);
    var texType = ext.halfFloatTexType;
    var rgba = ext.formatRGBA;
    var filtering = ext.supportLinearFiltering ? gl.LINEAR : gl.NEAREST;
    // Free bloom buffers from a previous init (resize) before re-allocating.
    if (this.bloom && this.bloom.destroy) this.bloom.destroy();
    if (this.bloomFramebuffers) {
      for (var bi = 0; bi < this.bloomFramebuffers.length; bi++) {
        if (this.bloomFramebuffers[bi] && this.bloomFramebuffers[bi].destroy) this.bloomFramebuffers[bi].destroy();
      }
    }
    this.bloom = this.createFBO(res.width, res.height, rgba.internalFormat, rgba.format, texType, filtering);
    this.bloomFramebuffers = [];
    for (var i = 0; i < config.BLOOM_ITERATIONS; i++) {
      var width = res.width >> (i + 1);
      var height = res.height >> (i + 1);
      if (width < 2 || height < 2) break;
      var fbo = this.createFBO(width, height, rgba.internalFormat, rgba.format, texType, filtering);
      this.bloomFramebuffers.push(fbo);
    }
  };

  /* ----------------------------------------------------------------- *
   * Simulation step
   * ----------------------------------------------------------------- */
  FluidSim.prototype.step = function (dt) {
    var gl = this.gl, config = this.config;
    var velocity = this.velocity, dye = this.dye;
    gl.disable(gl.BLEND);

    this.curlProgram.bind();
    gl.uniform2f(this.curlProgram.uniforms.texelSize, velocity.texelSizeX, velocity.texelSizeY);
    gl.uniform1i(this.curlProgram.uniforms.uVelocity, velocity.read.attach(0));
    this._blit(this.curl);

    this.vorticityProgram.bind();
    gl.uniform2f(this.vorticityProgram.uniforms.texelSize, velocity.texelSizeX, velocity.texelSizeY);
    gl.uniform1i(this.vorticityProgram.uniforms.uVelocity, velocity.read.attach(0));
    gl.uniform1i(this.vorticityProgram.uniforms.uCurl, this.curl.attach(1));
    gl.uniform1f(this.vorticityProgram.uniforms.curl, config.CURL);
    gl.uniform1f(this.vorticityProgram.uniforms.dt, dt);
    this._blit(velocity.write);
    velocity.swap();

    this.divergenceProgram.bind();
    gl.uniform2f(this.divergenceProgram.uniforms.texelSize, velocity.texelSizeX, velocity.texelSizeY);
    gl.uniform1i(this.divergenceProgram.uniforms.uVelocity, velocity.read.attach(0));
    this._blit(this.divergence);

    this.clearProgram.bind();
    gl.uniform1i(this.clearProgram.uniforms.uTexture, this.pressure.read.attach(0));
    gl.uniform1f(this.clearProgram.uniforms.value, config.PRESSURE);
    this._blit(this.pressure.write);
    this.pressure.swap();

    this.pressureProgram.bind();
    gl.uniform2f(this.pressureProgram.uniforms.texelSize, velocity.texelSizeX, velocity.texelSizeY);
    gl.uniform1i(this.pressureProgram.uniforms.uDivergence, this.divergence.attach(0));
    for (var i = 0; i < config.PRESSURE_ITERATIONS; i++) {
      gl.uniform1i(this.pressureProgram.uniforms.uPressure, this.pressure.read.attach(1));
      this._blit(this.pressure.write);
      this.pressure.swap();
    }

    this.gradienSubtractProgram.bind();
    gl.uniform2f(this.gradienSubtractProgram.uniforms.texelSize, velocity.texelSizeX, velocity.texelSizeY);
    gl.uniform1i(this.gradienSubtractProgram.uniforms.uPressure, this.pressure.read.attach(0));
    gl.uniform1i(this.gradienSubtractProgram.uniforms.uVelocity, velocity.read.attach(1));
    this._blit(velocity.write);
    velocity.swap();

    this.advectionProgram.bind();
    gl.uniform2f(this.advectionProgram.uniforms.texelSize, velocity.texelSizeX, velocity.texelSizeY);
    if (!this.ext.supportLinearFiltering) {
      gl.uniform2f(this.advectionProgram.uniforms.dyeTexelSize, velocity.texelSizeX, velocity.texelSizeY);
    }
    gl.uniform1i(this.advectionProgram.uniforms.uVelocity, velocity.read.attach(0));
    gl.uniform1i(this.advectionProgram.uniforms.uSource, velocity.read.attach(0));
    gl.uniform1f(this.advectionProgram.uniforms.dt, dt);
    gl.uniform1f(this.advectionProgram.uniforms.dissipation, config.VELOCITY_DISSIPATION);
    this._blit(velocity.write);
    velocity.swap();

    this.advectionProgram.bind();
    if (!this.ext.supportLinearFiltering) {
      gl.uniform2f(this.advectionProgram.uniforms.dyeTexelSize, dye.texelSizeX, dye.texelSizeY);
    }
    gl.uniform1i(this.advectionProgram.uniforms.uVelocity, velocity.read.attach(0));
    gl.uniform1i(this.advectionProgram.uniforms.uSource, dye.read.attach(1));
    gl.uniform1f(this.advectionProgram.uniforms.dissipation, config.DENSITY_DISSIPATION);
    this._blit(dye.write);
    dye.swap();
  };

  /* ----------------------------------------------------------------- *
   * Render
   * ----------------------------------------------------------------- */
  FluidSim.prototype.render = function (target) {
    if (this.config.BLOOM) this.applyBloom(this.dye.read, this.bloom);

    var gl = this.gl;
    gl.blendFunc(gl.ONE, gl.ONE_MINUS_SRC_ALPHA);
    gl.enable(gl.BLEND);
    this.drawDisplay(target);
  };

  FluidSim.prototype.drawDisplay = function (target) {
    var gl = this.gl;
    var width = target == null ? gl.drawingBufferWidth : target.width;
    var height = target == null ? gl.drawingBufferHeight : target.height;
    this.displayMaterial.bind();
    if (this.config.SHADING) gl.uniform2f(this.displayMaterial.uniforms.texelSize, 1.0 / width, 1.0 / height);
    gl.uniform1i(this.displayMaterial.uniforms.uTexture, this.dye.read.attach(0));
    if (this.config.BLOOM) gl.uniform1i(this.displayMaterial.uniforms.uBloom, this.bloom.attach(1));
    this._blit(target);
  };

  FluidSim.prototype.applyBloom = function (source, destination) {
    if (this.bloomFramebuffers.length < 2) return;
    var gl = this.gl, config = this.config;
    var last = destination;
    gl.disable(gl.BLEND);

    this.bloomPrefilterProgram.bind();
    var knee = config.BLOOM_THRESHOLD * config.BLOOM_SOFT_KNEE + 0.0001;
    var curve0 = config.BLOOM_THRESHOLD - knee;
    var curve1 = knee * 2;
    var curve2 = 0.25 / knee;
    gl.uniform3f(this.bloomPrefilterProgram.uniforms.curve, curve0, curve1, curve2);
    gl.uniform1f(this.bloomPrefilterProgram.uniforms.threshold, config.BLOOM_THRESHOLD);
    gl.uniform1i(this.bloomPrefilterProgram.uniforms.uTexture, source.attach(0));
    this._blit(last);

    this.bloomBlurProgram.bind();
    var i;
    for (i = 0; i < this.bloomFramebuffers.length; i++) {
      var dest = this.bloomFramebuffers[i];
      gl.uniform2f(this.bloomBlurProgram.uniforms.texelSize, last.texelSizeX, last.texelSizeY);
      gl.uniform1i(this.bloomBlurProgram.uniforms.uTexture, last.attach(0));
      this._blit(dest);
      last = dest;
    }

    gl.blendFunc(gl.ONE, gl.ONE);
    gl.enable(gl.BLEND);
    for (i = this.bloomFramebuffers.length - 2; i >= 0; i--) {
      var baseTex = this.bloomFramebuffers[i];
      gl.uniform2f(this.bloomBlurProgram.uniforms.texelSize, last.texelSizeX, last.texelSizeY);
      gl.uniform1i(this.bloomBlurProgram.uniforms.uTexture, last.attach(0));
      gl.viewport(0, 0, baseTex.width, baseTex.height);
      this._blit(baseTex);
      last = baseTex;
    }
    gl.disable(gl.BLEND);

    this.bloomFinalProgram.bind();
    gl.uniform2f(this.bloomFinalProgram.uniforms.texelSize, last.texelSizeX, last.texelSizeY);
    gl.uniform1i(this.bloomFinalProgram.uniforms.uTexture, last.attach(0));
    gl.uniform1f(this.bloomFinalProgram.uniforms.intensity, config.BLOOM_INTENSITY);
    this._blit(destination);
  };

  /* ----------------------------------------------------------------- *
   * Splats
   * ----------------------------------------------------------------- */
  FluidSim.prototype.splat = function (x, y, dx, dy, color) {
    var gl = this.gl;
    this.splatProgram.bind();
    gl.uniform1i(this.splatProgram.uniforms.uTarget, this.velocity.read.attach(0));
    gl.uniform1f(this.splatProgram.uniforms.aspectRatio, this.canvas.width / this.canvas.height);
    gl.uniform2f(this.splatProgram.uniforms.point, x, y);
    gl.uniform3f(this.splatProgram.uniforms.color, dx, dy, 0.0);
    gl.uniform1f(this.splatProgram.uniforms.radius, correctRadius(this.config.SPLAT_RADIUS / 100.0, this.canvas));
    this._blit(this.velocity.write);
    this.velocity.swap();

    gl.uniform1i(this.splatProgram.uniforms.uTarget, this.dye.read.attach(0));
    gl.uniform3f(this.splatProgram.uniforms.color, color.r, color.g, color.b);
    this._blit(this.dye.write);
    this.dye.swap();
  };

  FluidSim.prototype.splatPointer = function (pointer) {
    var dx = pointer.deltaX * this.config.SPLAT_FORCE;
    var dy = pointer.deltaY * this.config.SPLAT_FORCE;
    this.splat(pointer.texcoordX, pointer.texcoordY, dx, dy, pointer.color);
  };

  FluidSim.prototype.applyInputs = function () {
    for (var i = 0; i < this.pointers.length; i++) {
      var p = this.pointers[i];
      if (p.moved) {
        p.moved = false;
        this.splatPointer(p);
      }
    }
  };

  /* ----------------------------------------------------------------- *
   * Pointer / colour helpers
   * ----------------------------------------------------------------- */
  function createPointer() {
    return {
      id: -1, texcoordX: 0, texcoordY: 0, prevTexcoordX: 0, prevTexcoordY: 0,
      deltaX: 0, deltaY: 0, down: false, moved: false, color: { r: 0, g: 0, b: 0 }
    };
  }

  function correctRadius(radius, canvas) {
    var aspectRatio = canvas.width / canvas.height;
    if (aspectRatio > 1) radius *= aspectRatio;
    return radius;
  }

  function updatePointerMoveData(pointer, posX, posY, color, canvas) {
    pointer.prevTexcoordX = pointer.texcoordX;
    pointer.prevTexcoordY = pointer.texcoordY;
    pointer.texcoordX = posX / canvas.clientWidth;
    pointer.texcoordY = 1.0 - posY / canvas.clientHeight;
    pointer.deltaX = correctDeltaX(pointer.texcoordX - pointer.prevTexcoordX, canvas);
    pointer.deltaY = correctDeltaY(pointer.texcoordY - pointer.prevTexcoordY, canvas);
    pointer.moved = Math.abs(pointer.deltaX) > 0 || Math.abs(pointer.deltaY) > 0;
    pointer.color = color;
  }

  function correctDeltaX(delta, canvas) {
    var aspectRatio = canvas.width / canvas.height;
    if (aspectRatio < 1) delta *= aspectRatio;
    return delta;
  }
  function correctDeltaY(delta, canvas) {
    var aspectRatio = canvas.width / canvas.height;
    if (aspectRatio > 1) delta /= aspectRatio;
    return delta;
  }

  function generateColor(mult) {
    if (mult == null) { mult = 0.15; }
    var c = HSVtoRGB(Math.random(), 1.0, 1.0);
    c.r *= mult; c.g *= mult; c.b *= mult;
    return c;
  }

  function HSVtoRGB(h, s, v) {
    var r, g, b, i, f, p, q, t;
    i = Math.floor(h * 6);
    f = h * 6 - i;
    p = v * (1 - s);
    q = v * (1 - f * s);
    t = v * (1 - (1 - f) * s);
    switch (i % 6) {
      case 0: r = v; g = t; b = p; break;
      case 1: r = q; g = v; b = p; break;
      case 2: r = p; g = v; b = t; break;
      case 3: r = p; g = q; b = v; break;
      case 4: r = t; g = p; b = v; break;
      case 5: r = v; g = p; b = q; break;
    }
    return { r: r, g: g, b: b };
  }

  function getResolution(gl, resolution) {
    var aspectRatio = gl.drawingBufferWidth / gl.drawingBufferHeight;
    if (aspectRatio < 1) aspectRatio = 1.0 / aspectRatio;
    var min = Math.round(resolution);
    var max = Math.round(resolution * aspectRatio);
    if (gl.drawingBufferWidth > gl.drawingBufferHeight) return { width: max, height: min };
    return { width: min, height: max };
  }

  function now() { return (window.performance && window.performance.now) ? window.performance.now() : new Date().getTime(); }

  /* ----------------------------------------------------------------- *
   * Lifecycle
   * ----------------------------------------------------------------- */
  FluidSim.prototype.resize = function () {
    var dpr = Math.min(window.devicePixelRatio || 1, this.config.MAX_DPR);
    var width = Math.floor(this.canvas.clientWidth * dpr);
    var height = Math.floor(this.canvas.clientHeight * dpr);
    if (this.canvas.width !== width || this.canvas.height !== height) {
      this.canvas.width = width;
      this.canvas.height = height;
      return true;
    }
    return false;
  };

  FluidSim.prototype._bindEvents = function () {
    var self = this;
    var add = function (target, type, handler, opts) {
      target.addEventListener(type, handler, opts);
      self._listeners.push({ target: target, type: type, handler: handler });
    };

    var move = function (clientX, clientY) {
      var pointer = self.pointers[0];
      var rect = self.canvas.getBoundingClientRect();
      var posX = clientX - rect.left;
      var posY = clientY - rect.top;
      if (!pointer.down) {
        pointer.down = true;
        pointer.color = generateColor(self.config.INTENSITY * 0.15);
        pointer.prevTexcoordX = posX / self.canvas.clientWidth;
        pointer.prevTexcoordY = 1.0 - posY / self.canvas.clientHeight;
        pointer.texcoordX = pointer.prevTexcoordX;
        pointer.texcoordY = pointer.prevTexcoordY;
      }
      updatePointerMoveData(pointer, posX, posY, pointer.color, self.canvas);
    };

    add(window, 'mousemove', function (e) { move(e.clientX, e.clientY); });
    add(window, 'touchmove', function (e) {
      if (!e.touches || !e.touches.length) return;
      move(e.touches[0].clientX, e.touches[0].clientY);
    }, { passive: true });

    // Debounce resize so dragging / mobile URL-bar toggles do not thrash the
    // (now leak-free) framebuffer re-allocation.
    add(window, 'resize', function () {
      if (self._resizeTimer) { window.clearTimeout(self._resizeTimer); }
      self._resizeTimer = window.setTimeout(function () {
        self._resizeTimer = null;
        if (self.running && self.resize()) self.initFramebuffers();
      }, 200);
    });

    // If the GPU resets / context is lost, stop cleanly instead of throwing
    // every frame.
    add(self.canvas, 'webglcontextlost', function (e) {
      if (e && e.preventDefault) { e.preventDefault(); }
      self.contextLost = true;
      self.stop();
    });
  };

  FluidSim.prototype.frame = function () {
    if (this.contextLost) return;
    var t = now();
    var dt = Math.min((t - this.lastUpdate) / 1000, 0.016666);
    this.lastUpdate = t;

    if (this.config.COLORFUL) {
      this.colorUpdateTimer += dt * this.config.COLOR_UPDATE_SPEED;
      if (this.colorUpdateTimer >= 1) {
        this.colorUpdateTimer = this.colorUpdateTimer % 1;
        for (var i = 0; i < this.pointers.length; i++) this.pointers[i].color = generateColor(this.config.INTENSITY * 0.15);
      }
    }

    this.applyInputs();
    if (!this.config.PAUSED) this.step(dt);
    this.render(null);
  };

  FluidSim.prototype.start = function () {
    if (this.unsupported || this.running) return;
    this.running = true;
    var self = this;
    var loop = function () {
      if (!self.running) return;
      try {
        self.frame();
      } catch (err) {
        if (window.console && console.warn) { console.warn('[BubbleCursor] fluid stopped after a runtime error:', err); }
        self.stop();
        return;
      }
      self._raf = window.requestAnimationFrame(loop);
    };
    this.lastUpdate = now();
    this._raf = window.requestAnimationFrame(loop);
  };

  FluidSim.prototype.stop = function () {
    this.running = false;
    if (this._raf) window.cancelAnimationFrame(this._raf);
    this._raf = null;
  };

  FluidSim.prototype.destroy = function () {
    this.stop();
    if (this._resizeTimer) { window.clearTimeout(this._resizeTimer); this._resizeTimer = null; }
    for (var i = 0; i < this._listeners.length; i++) {
      var l = this._listeners[i];
      l.target.removeEventListener(l.type, l.handler);
    }
    this._listeners = [];
    this._freeGL();
  };

  // Release every GPU resource. Called on teardown (page unload / re-init) so
  // nothing accumulates across navigations or settings changes.
  FluidSim.prototype._freeGL = function () {
    var del = function (f) { if (f && f.destroy) f.destroy(); };
    var delDouble = function (d) { if (d) { del(d.read); del(d.write); } };
    delDouble(this.dye); delDouble(this.velocity); delDouble(this.pressure);
    del(this.divergence); del(this.curl); del(this.bloom);
    if (this.bloomFramebuffers) {
      for (var i = 0; i < this.bloomFramebuffers.length; i++) del(this.bloomFramebuffers[i]);
    }
    this.dye = this.velocity = this.pressure = null;
    this.divergence = this.curl = this.bloom = null;
    this.bloomFramebuffers = [];
    var gl = this.gl;
    if (gl) {
      var lose = gl.getExtension('WEBGL_lose_context');
      if (lose) { try { lose.loseContext(); } catch (e) { /* ignore */ } }
    }
  };

  /* ----------------------------------------------------------------- *
   * Public API
   * ----------------------------------------------------------------- */
  window.BubbleCursorFluid = {
    start: function (canvas, config) {
      if (instance) instance.destroy();
      instance = new FluidSim(canvas, config);
      if (instance.unsupported) { instance = null; return null; }
      instance.start();
      return instance;
    },
    stop: function () {
      if (instance) { instance.destroy(); instance = null; }
    },
    isRunning: function () { return !!(instance && instance.running); }
  };
})(window, document);
