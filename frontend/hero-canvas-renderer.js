// One visible canvas; small reusable buffers avoid a full-screen blur pass.
export function createHeroCanvasRenderer(canvas){
  const background=document.createElement('canvas'),foreground=document.createElement('canvas');
  background.width=64;background.height=36;
  let context,backgroundContext,foregroundContext;
  let cssWidth=1,cssHeight=1,width=1,height=1,dirty=true,backgroundFrame=-1,backgroundQuality,lite=false;
  const fill='#05070d';
  const edgeA=document.createElement('canvas'),edgeB=document.createElement('canvas');
  // Copies a strip of the backdrop (with the 40% veil) into a tiny canvas whose
  // opacity fades from the outer edge (opaque) towards the picture.
  function seam(edge,sx,sy,sw,sh,horizontal,opaqueAtStart){
    // 32 steps along the fade, so the gradient reaches full opacity at the seam.
    const ew=horizontal?32:64,eh=horizontal?36:32;
    if(edge.width!==ew||edge.height!==eh){edge.width=ew;edge.height=eh;}
    const c=edge.getContext('2d');
    c.globalCompositeOperation='source-over';c.clearRect(0,0,ew,eh);
    c.drawImage(background,sx,sy,Math.max(.5,sw),Math.max(.5,sh),0,0,ew,eh);
    c.fillStyle='rgba(0,0,0,.4)';c.fillRect(0,0,ew,eh);
    c.globalCompositeOperation='destination-in';
    const fade=horizontal?c.createLinearGradient(0,0,ew,0):c.createLinearGradient(0,0,0,eh);
    fade.addColorStop(0,opaqueAtStart?'#000':'rgba(0,0,0,0)');fade.addColorStop(1,opaqueAtStart?'rgba(0,0,0,0)':'#000');
    c.fillStyle=fade;c.fillRect(0,0,ew,eh);c.globalCompositeOperation='source-over';
  }

  function resize(w,h){
    w=Math.max(1,Math.round(w));h=Math.max(1,Math.round(h));
    // Keep large/retina viewports within a 1080p pixel budget; the light mode
    // for weak devices paints one canvas pixel per CSS pixel.
    const density=Math.min(devicePixelRatio||1,lite?1:1.5,Math.sqrt(2073600/(w*h)));
    const nextWidth=Math.max(1,Math.round(w*density)),nextHeight=Math.max(1,Math.round(h*density));
    if(w===cssWidth&&h===cssHeight&&nextWidth===width&&nextHeight===height)return;
    cssWidth=w;cssHeight=h;width=nextWidth;height=nextHeight;dirty=true;
  }

  function render(image,index,quality){
    context||=canvas.getContext('2d',{alpha:false,desynchronized:true});
    backgroundContext||=background.getContext('2d',{alpha:false});
    foregroundContext||=foreground.getContext('2d');
    if(!context||!backgroundContext||!foregroundContext)throw new Error('Canvas unavailable');
    const sourceWidth=image.naturalWidth||image.width,sourceHeight=image.naturalHeight||image.height;
    if(!sourceWidth||!sourceHeight)throw new Error('Empty hero image');
    if(canvas.width!==width||canvas.height!==height){canvas.width=width;canvas.height=height;}
    context.imageSmoothingEnabled=true;context.imageSmoothingQuality='high';
    context.fillStyle=fill;context.fillRect(0,0,width,height);

    if(backgroundFrame<0||Math.abs(index-backgroundFrame)>=3||backgroundQuality!==quality){
      const scale=Math.max(64/sourceWidth,36/sourceHeight);
      const w=sourceWidth*scale,h=sourceHeight*scale;
      backgroundContext.fillStyle=fill;backgroundContext.fillRect(0,0,64,36);
      // Blur only 2,304 pixels. Overscan keeps the thumbnail edges opaque.
      backgroundContext.filter='blur(1px)';
      backgroundContext.drawImage(image,(64-w)/2-2,(36-h)/2-2,w+4,h+4);
      backgroundContext.filter='none';
      backgroundFrame=index;backgroundQuality=quality;
    }
    const cover=Math.max(width/64,height/36);
    context.drawImage(background,(width-64*cover)/2,(height-36*cover)/2,64*cover,36*cover);
    context.fillStyle='rgba(0,0,0,.4)';context.fillRect(0,0,width,height);

    const contain=Math.min(width/sourceWidth,height/sourceHeight);
    const w=sourceWidth*contain,h=sourceHeight*contain,x=(width-w)/2,y=(height-h)/2;
    // A frame that fills the banner, or the light mode, needs no feathering:
    // one direct draw instead of an extra full-size compositing pass.
    if(x<=1&&y<=1){context.drawImage(image,x,y,w,h);dirty=false;return;}
    if(lite){
      // Light mode: one direct draw, then the seams are covered by strips cut
      // from the tiny blurred backdrop that fade into the frame (a few hundred
      // pixels of work instead of masking the whole picture).
      context.drawImage(image,x,y,w,h);
      const bgX=(width-64*cover)/2,bgY=(height-36*cover)/2;
      if(x>1){
        const band=Math.min(100*width/cssWidth,w*.15);
        seam(edgeA,(x-bgX)/cover,0,band/cover,36,true,true);context.drawImage(edgeA,x,bgY,band,36*cover);
        seam(edgeB,(x+w-band-bgX)/cover,0,band/cover,36,true,false);context.drawImage(edgeB,x+w-band,bgY,band,36*cover);
      }
      if(y>1){
        const band=Math.min(100*height/cssHeight,h*.12);
        seam(edgeA,0,(y-bgY)/cover,64,band/cover,false,true);context.drawImage(edgeA,bgX,y,64*cover,band);
        seam(edgeB,0,(y+h-band-bgY)/cover,64,band/cover,false,false);context.drawImage(edgeB,bgX,y+h-band,64*cover,band);
      }
      dirty=false;return;
    }
    // The feather buffer matches the drawn size, not the (often larger) source.
    const bufferWidth=Math.max(1,Math.round(Math.min(w,sourceWidth))),bufferHeight=Math.max(1,Math.round(Math.min(h,sourceHeight)));
    if(foreground.width!==bufferWidth||foreground.height!==bufferHeight){
      foreground.width=bufferWidth;foreground.height=bufferHeight;
    }
    foregroundContext.globalCompositeOperation='source-over';
    foregroundContext.clearRect(0,0,bufferWidth,bufferHeight);
    foregroundContext.drawImage(image,0,0,bufferWidth,bufferHeight);
    foregroundContext.globalCompositeOperation='destination-in';
    // Feather only the edges adjoining a letterbox area; the car stays sharp.
    if(x>1){
      const fade=Math.min(100*width/cssWidth/w,.15);
      const mask=foregroundContext.createLinearGradient(0,0,bufferWidth,0);
      mask.addColorStop(0,'rgba(0,0,0,0)');mask.addColorStop(fade,'#000');
      mask.addColorStop(1-fade,'#000');mask.addColorStop(1,'rgba(0,0,0,0)');
      foregroundContext.fillStyle=mask;foregroundContext.fillRect(0,0,bufferWidth,bufferHeight);
    }
    if(y>1){
      const fade=Math.min(100*height/cssHeight/h,.12);
      const mask=foregroundContext.createLinearGradient(0,0,0,bufferHeight);
      mask.addColorStop(0,'rgba(0,0,0,0)');mask.addColorStop(fade,'#000');
      mask.addColorStop(1-fade,'#000');mask.addColorStop(1,'rgba(0,0,0,0)');
      foregroundContext.fillStyle=mask;foregroundContext.fillRect(0,0,bufferWidth,bufferHeight);
    }
    foregroundContext.globalCompositeOperation='source-over';
    context.drawImage(foreground,x,y,w,h);
    dirty=false;
  }

  function setLite(value){
    if(lite===value)return;
    lite=value;backgroundFrame=-1;cssWidth=-1;resize(canvas.clientWidth||1,canvas.clientHeight||1);dirty=true;
  }

  return {resize,render,setLite,needsResize:()=>dirty,reset:()=>{backgroundFrame=-1;backgroundQuality=undefined;dirty=true;}};
}
