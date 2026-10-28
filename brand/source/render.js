const { chromium } = require('playwright'); const fs=require('fs'); const path=require('path');
(async () => {
  const outRoot = process.argv[2]; const only = process.argv[3];
  const jobs = fs.readFileSync('compose/jobs.tsv','utf8').trim().split('\n').map(l=>l.split('\t'));
  const b = await chromium.launch();
  for (const [name,w,h,out] of jobs) {
    if (only && !name.includes(only)) continue;
    const p = await b.newPage({viewport:{width:+w,height:+h}});
    await p.goto('file://'+path.resolve('compose',name+'.html')); await p.evaluate(()=>document.fonts.ready); await p.waitForTimeout(150);
    const dest = path.join(outRoot,out); fs.mkdirSync(path.dirname(dest),{recursive:true});
    await p.screenshot({path:dest, omitBackground:true}); await p.close();
  }
  await b.close(); console.log('ok');
})();
