// node video.js <svg> <outDir> <W> <H> <logoW> <fps> <dur> <startIdx> [bg]
const { chromium } = require('playwright'); const fs=require('fs'); const path=require('path');
(async () => {
  const [,, svg, outDir, W, H, LW, fps, dur, start, bg] = process.argv;
  const src = fs.readFileSync(svg,'utf8');
  const b = await chromium.launch(); const p = await b.newPage({viewport:{width:+W,height:+H}});
  const html = `<html><body style="margin:0;width:${W}px;height:${H}px;display:grid;place-items:center;background:${bg||'transparent'}">
    <div style="width:${LW}px">${src.replace('<svg ', '<svg style="width:100%;height:auto;display:block" ')}</div></body></html>`;
  await p.setContent(html); await p.waitForTimeout(100);
  fs.mkdirSync(outDir,{recursive:true});
  const n = Math.round(+dur * +fps);
  for (let i=0;i<n;i++){
    const t = i*1000/(+fps);
    await p.evaluate(t => document.getAnimations().forEach(a => { a.pause(); a.currentTime = t; }), t);
    await p.screenshot({path:`${outDir}/f${String(+start+i).padStart(5,'0')}.png`, omitBackground: !bg});
  }
  await b.close(); console.log('frames', n);
})();
