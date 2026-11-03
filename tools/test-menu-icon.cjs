// WordPress svgPainter recolors every fill; the chat dots must stay transparent.
// NODE_PATH=/path/to/node_modules node tools/test-menu-icon.cjs [plugin-tree]
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { Resvg } = require('@resvg/resvg-js');
const tree = process.argv[2] || 'src/gapineh';
const php = fs.readFileSync(path.join(tree, 'includes/class-settings.php'), 'utf8');
const match = php.match(/const MENU_ICON = 'data:image\/svg\+xml;base64,([^']+)'/);
assert(match, 'menu icon must be inline SVG');
const svg = Buffer.from(match[1], 'base64').toString('utf8');
assert.equal(svg, fs.readFileSync(path.join(__dirname, 'menu-icon.svg'), 'utf8'));
for (const color of ['#a7aaad', '#ffffff', '#333333']) {
  // This is the same replacement performed by WordPress svg-painter.js.
  const painted = svg.replace(/fill="(.+?)"/g, `fill="${color}"`);
  const image = new Resvg(painted, {fitTo: {mode: 'zoom', value: 10}}).render();
  const alpha = (x,y) => image.pixels[(y * image.width + x) * 4 + 3];
  // Bubble body must be filled; all three dots must be holes even after repainting.
  assert.equal(alpha(100,45),255,`body visible with ${color}`);
  [61,100,138].forEach(x => assert.equal(alpha(x,83),0,`dot transparent with ${color}`));
}
console.log('✅ Menu chat icon survives WordPress recoloring');
