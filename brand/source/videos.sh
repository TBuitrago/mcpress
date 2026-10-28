#!/usr/bin/env bash
# GIF, MP4 y WebM con transparencia del logo animado. Requiere ffmpeg y `npm install`.
set -e; cd "$(dirname "$0")"; A=../logo/animated; V=$A/video; mkdir -p $V tmp
gif(){ ffmpeg -v error -y -framerate $2 -i $1/f%05d.png -vf "split[a][b];[a]palettegen=max_colors=64:stats_mode=full[p];[b][p]paletteuse=dither=sierra2_4a" -loop 0 $3; }
node video.js $A/mcpress-logo-loop-light.svg tmp/gl 760 220 720 25 3.6 0 '#F4F1EA' && gif tmp/gl 25 $V/mcpress-logo-loop-light.gif
node video.js $A/mcpress-logo-loop-dark.svg tmp/gd 760 220 720 25 3.6 0 '#0D1A2E' && gif tmp/gd 25 $V/mcpress-logo-loop-dark.gif
node video.js $A/mcpress-symbol-loader-light.svg tmp/ld 240 240 220 30 1.8 0 '#FFFFFF' && gif tmp/ld 30 $V/mcpress-symbol-loader.gif
for th in light dark; do
  bg=$([ $th = light ] && echo '#F4F1EA' || echo '#0D1A2E')
  node video.js $A/mcpress-logo-intro-$th.svg tmp/v$th 1920 1080 1240 30 2.6 0 "$bg"
  node video.js $A/mcpress-logo-loop-$th.svg tmp/v$th 1920 1080 1240 30 7.2 78 "$bg"
  ffmpeg -v error -y -framerate 30 -i tmp/v$th/f%05d.png -c:v libx264 -preset veryfast -crf 18 -pix_fmt yuv420p -movflags +faststart $V/mcpress-logo-intro-$th-1080p.mp4
done
node video.js $A/mcpress-logo-intro-light.svg tmp/al 1920 540 1800 30 3.0 0
ffmpeg -v error -y -framerate 30 -i tmp/al/f%05d.png -c:v libvpx-vp9 -pix_fmt yuva420p -b:v 0 -crf 30 -row-mt 1 -auto-alt-ref 0 $V/mcpress-logo-intro-alpha.webm
rm -rf tmp
