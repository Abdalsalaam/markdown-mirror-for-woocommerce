#!/usr/bin/env node
/**
 * gen-wporg-assets.mjs - re-runnable WordPress.org listing asset generator.
 *
 * Writes the plugin directory assets into `.wordpress.org/`:
 *   icon.svg, icon-128x128.png, icon-256x256.png,
 *   banner-772x250.png, banner-1544x500.png
 *
 * NOT part of the plugin build; `.wordpress.org/` is dist-ignored. Screenshots are
 * captured separately (real wp-env captures, never mockups) - see scripts/wporg-screenshots.mjs.
 *
 * Brand source of truth lives in the agentmint site repo (the plugin is published by
 * AgentMint): the robot-and-leaf mark inside `public/logo-black.svg` and the brand
 * accent #1670e4, following the same mark-extraction rule as that repo's
 * scripts/gen-favicons.mjs (mark = the six paths whose translate x < 360). This script
 * also borrows that repo's node_modules (sharp, satori) and its Hanken Grotesk fonts,
 * so the plugin repo carries no image tooling of its own.
 *
 * Usage: node scripts/gen-wporg-assets.mjs
 *   AGENTMINT_DIR=/path/to/agentmint (default: ../agentmint next to this repo)
 */
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const PLUGIN_ROOT = resolve(HERE, '..');
const AGENTMINT = process.env.AGENTMINT_DIR || resolve(PLUGIN_ROOT, '..', 'agentmint');
const OUT = join(PLUGIN_ROOT, '.wordpress.org');

const req = createRequire(join(AGENTMINT, 'package.json'));
const sharp = req('sharp');
const satoriMod = await import(pathToFileURL(req.resolve('satori')).href);
const satori = satoriMod.default?.default ?? satoriMod.default ?? satoriMod;

// Brand tokens (in sync with the agentmint site's --accent token and OG card palette).
const TILE = '#1670e4';
const INK = '#ffffff';

const FONT_DIR = join(AGENTMINT, 'node_modules/@fontsource/hanken-grotesk/files');
const font = (weight) => readFileSync(join(FONT_DIR, `hanken-grotesk-latin-${weight}-normal.woff`));
const FONTS = [400, 500, 600, 700, 800].map((weight) => ({
  name: 'Hanken Grotesk',
  data: font(weight),
  weight,
  style: 'normal',
}));

// --- Extract the mark (same selection rule as agentmint scripts/gen-favicons.mjs) ---
const wordmark = readFileSync(join(AGENTMINT, 'public/logo-black.svg'), 'utf8');
const pathEls = [...wordmark.matchAll(/<path\b[^>]*>/g)].map((m) => m[0]);
const translateX = (p) => {
  const m = p.match(/translate\(\s*([-\d.]+)/);
  return m ? parseFloat(m[1]) : 0;
};
const markPaths = pathEls.filter((p) => translateX(p) < 360 && /\bd="[^"]/.test(p));
if (markPaths.length !== 6) {
  console.warn(`gen-wporg-assets: expected 6 mark paths, found ${markPaths.length} - verify logo-black.svg.`);
}
const markWhite = markPaths.map((p) => p.replace(/fill="[^"]*"/, `fill="${INK}"`)).join('');

// --- Measure the mark bbox in the 1269x314 source space (render + alpha scan) ---
async function measureBbox() {
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="1269" height="314">${markWhite}</svg>`;
  const { data, info } = await sharp(Buffer.from(svg)).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
  const { width, height, channels } = info;
  let minX = Infinity, minY = Infinity, maxX = -1, maxY = -1;
  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      if (data[(y * width + x) * channels + (channels - 1)] > 10) {
        if (x < minX) minX = x;
        if (x > maxX) maxX = x;
        if (y < minY) minY = y;
        if (y > maxY) maxY = y;
      }
    }
  }
  return { x: minX, y: minY, w: maxX - minX + 1, h: maxY - minY + 1 };
}

/**
 * Per-plugin badge glyph (the one thing that changes across the AgentMint plugin
 * collection - the tile, mark, and layout stay identical). For Product Markdown
 * Mirror it is the Markdown Mark (dcurtis/markdown-mark, CC0/public domain),
 * drawn in brand blue on a white chip. Native glyph space: 208x128.
 */
const BADGE = {
  glyphW: 208,
  glyphH: 128,
  path: 'M30 98V30h20l20 25 20-25h20v68H90V59L70 84 50 59v39zm125 0l-30-33h20V30h20v35h20z',
};

/**
 * Full-bleed square tile (wp.org rounds icon corners itself): the white AgentMint
 * mark sits up-left of center, the plugin badge chip sits bottom-right.
 */
function iconSvg(bbox, size, inner) {
  const s = inner / Math.max(bbox.w, bbox.h);
  const drawW = bbox.w * s;
  const drawH = bbox.h * s;
  // Mark center nudged up-left to make room for the badge chip.
  const cx = size * 0.44;
  const cy = size * 0.43;
  const tx = cx - drawW / 2 - bbox.x * s;
  const ty = cy - drawH / 2 - bbox.y * s;

  // Badge chip: white rounded rect, glyph centered in brand blue.
  const chipW = size * 0.40;
  const chipH = chipW * (BADGE.glyphH / BADGE.glyphW) * 1.04;
  const chipX = size - chipW - size * 0.055;
  const chipY = size - chipH - size * 0.055;
  const chipR = chipW * 0.11;
  const gs = (chipW * 0.82) / BADGE.glyphW;
  const gx = chipX + (chipW - BADGE.glyphW * gs) / 2;
  const gy = chipY + (chipH - BADGE.glyphH * gs) / 2;

  return (
    `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">` +
    `<rect width="${size}" height="${size}" fill="${TILE}"/>` +
    `<g transform="translate(${tx.toFixed(3)},${ty.toFixed(3)}) scale(${s.toFixed(6)})">${markWhite}</g>` +
    `<rect x="${chipX.toFixed(2)}" y="${chipY.toFixed(2)}" width="${chipW.toFixed(2)}" height="${chipH.toFixed(2)}" rx="${chipR.toFixed(2)}" ry="${chipR.toFixed(2)}" fill="${INK}"/>` +
    `<g transform="translate(${gx.toFixed(3)},${gy.toFixed(3)}) scale(${gs.toFixed(6)})"><path d="${BADGE.path}" fill="${TILE}"/></g>` +
    `</svg>`
  );
}

/** White mark on transparent, rasterized once for use inside the satori banner. */
async function markPng(bbox, px) {
  const pad = Math.round(Math.max(bbox.w, bbox.h) * 0.03);
  const view = `${bbox.x - pad} ${bbox.y - pad} ${bbox.w + 2 * pad} ${bbox.h + 2 * pad}`;
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${view}">${markWhite}</svg>`;
  const png = await sharp(Buffer.from(svg)).resize(px, px, { fit: 'inside' }).png().toBuffer();
  return `data:image/png;base64,${png.toString('base64')}`;
}

const BANNER_W = 1544;
const BANNER_H = 500;

/** The satori element tree for the banner (object form, no JSX). */
function banner(markSmall, markBig) {
  const div = (style, children) => ({ type: 'div', props: { style, children } });
  return div(
    {
      width: `${BANNER_W}px`,
      height: `${BANNER_H}px`,
      display: 'flex',
      flexDirection: 'column',
      justifyContent: 'center',
      position: 'relative',
      padding: '0 96px',
      color: INK,
      fontFamily: 'Hanken Grotesk',
      backgroundColor: '#0b2a6e',
      backgroundImage:
        'radial-gradient(1100px 560px at 8% 0%, #2f6bde 0%, rgba(47,107,222,0) 46%),' +
        'radial-gradient(900px 680px at 96% 110%, #0c2c72 0%, rgba(12,44,114,0) 55%),' +
        'linear-gradient(135deg, #1a56c4, #0b2a6e)',
    },
    [
      // Oversized watermark mark, right side.
      {
        type: 'img',
        props: {
          src: markBig,
          width: 460,
          height: 440,
          style: {
            position: 'absolute',
            right: '48px',
            top: '46px',
            width: '460px',
            height: '440px',
            opacity: 0.16,
          },
        },
      },
      // Brand row.
      div({ display: 'flex', alignItems: 'center', marginBottom: '26px' }, [
        {
          type: 'img',
          props: {
            src: markSmall,
            width: 54,
            height: 52,
            style: { width: '54px', height: '52px', marginRight: '16px' },
          },
        },
        div({ display: 'flex', fontSize: '30px', fontWeight: 800, letterSpacing: '-0.02em' }, 'AgentMint'),
      ]),
      // Title.
      div(
        {
          display: 'flex',
          fontSize: '78px',
          fontWeight: 800,
          lineHeight: 1.04,
          letterSpacing: '-0.02em',
          maxWidth: '1080px',
        },
        'Markdown Mirror for WooCommerce',
      ),
      // Subtitle.
      div(
        {
          display: 'flex',
          marginTop: '22px',
          fontSize: '32px',
          fontWeight: 500,
          color: 'rgba(255,255,255,0.86)',
          maxWidth: '1100px',
        },
        'Read-only Markdown mirrors of your WooCommerce product pages',
      ),
      // URL chip.
      div({ display: 'flex', marginTop: '30px' }, [
        div(
          {
            display: 'flex',
            padding: '12px 24px',
            borderRadius: '12px',
            backgroundColor: 'rgba(4,16,44,0.45)',
            border: '1px solid rgba(157,193,255,0.35)',
            fontSize: '28px',
            fontWeight: 600,
            color: '#cfe0ff',
            letterSpacing: '0.01em',
          },
          '{product-url}.md',
        ),
      ]),
    ],
  );
}

async function main() {
  mkdirSync(OUT, { recursive: true });
  const bbox = await measureBbox();
  console.log(`mark bbox (source space): x${bbox.x} y${bbox.y} w${bbox.w} h${bbox.h}`);

  const out = (name, buf) => {
    writeFileSync(join(OUT, name), buf);
    console.log(`  wrote .wordpress.org/${name} (${buf.length} bytes)`);
  };

  // Icons: full-bleed tile, mark at ~58% of canvas (matches the brand favicon proportions).
  const icon512 = iconSvg(bbox, 512, 300);
  out('icon.svg', Buffer.from(icon512.replace('<svg ', '<svg version="1.1" ')));
  out('icon-256x256.png', await sharp(Buffer.from(icon512)).resize(256, 256).png().toBuffer());
  out('icon-128x128.png', await sharp(Buffer.from(icon512)).resize(128, 128).png().toBuffer());

  // Banner: render 1544x500 with satori, downscale for the 1x variant.
  const markSmall = await markPng(bbox, 108);
  const markBig = await markPng(bbox, 920);
  const svg = await satori(banner(markSmall, markBig), { width: BANNER_W, height: BANNER_H, fonts: FONTS });
  const banner2x = await sharp(Buffer.from(svg)).png().toBuffer();
  out('banner-1544x500.png', banner2x);
  out('banner-772x250.png', await sharp(banner2x).resize(772, 250).png().toBuffer());

  console.log('gen-wporg-assets: done.');
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
