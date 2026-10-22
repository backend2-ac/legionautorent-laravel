// One visible canvas; small reusable buffers avoid a full-screen blur pass.
export function createHeroCanvasRenderer(canvas){
  const background=document.createElement('canvas'),foreground=document.createElement('canvas');
  background.width=64;background.height=36;
  let context,backgroundContext,foregroundContext;
  let cssWidth=1,cssHeight=1,width=1,height=1,dirty=true,backgroundFrame=-1,backgroundQuality;
  const fill='#05070d';

  function resize(w,h){
    w=Math.max(1,Math.round(w));h=Math.max(1,Math.round(h));
    // Keep large/retina viewports within a 1080p pixel budget.
    const density=Math.min(devicePixelRatio||1,1.5,Math.sqrt(2073600/(w*h)));
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
    if(foreground.width!==sourceWidth||foreground.height!==sourceHeight){
      foreground.width=sourceWidth;foreground.height=sourceHeight;
    }
    foregroundContext.globalCompositeOperation='source-over';
    foregroundContext.clearRect(0,0,sourceWidth,sourceHeight);
    foregroundContext.drawImage(image,0,0);
    foregroundContext.globalCompositeOperation='destination-in';
    // Feather only the edges adjoining a letterbox area; the car stays sharp.
    if(x>1){
      const fade=Math.min(100*width/cssWidth/w,.15);
      const mask=foregroundContext.createLinearGradient(0,0,sourceWidth,0);
      mask.addColorStop(0,'rgba(0,0,0,0)');mask.addColorStop(fade,'#000');
      mask.addColorStop(1-fade,'#000');mask.addColorStop(1,'rgba(0,0,0,0)');
      foregroundContext.fillStyle=mask;foregroundContext.fillRect(0,0,sourceWidth,sourceHeight);
    }
    if(y>1){
      const fade=Math.min(100*height/cssHeight/h,.12);
      const mask=foregroundContext.createLinearGradient(0,0,0,sourceHeight);
      mask.addColorStop(0,'rgba(0,0,0,0)');mask.addColorStop(fade,'#000');
      mask.addColorStop(1-fade,'#000');mask.addColorStop(1,'rgba(0,0,0,0)');
      foregroundContext.fillStyle=mask;foregroundContext.fillRect(0,0,sourceWidth,sourceHeight);
    }
    foregroundContext.globalCompositeOperation='source-over';
    context.drawImage(foreground,x,y,w,h);
    dirty=false;
  }

  return {resize,render,needsResize:()=>dirty,reset:()=>{backgroundFrame=-1;backgroundQuality=undefined;dirty=true;}};
}
