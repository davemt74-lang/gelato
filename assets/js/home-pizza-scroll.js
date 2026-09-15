(() => {
  'use strict';

  const story = document.getElementById('pizzaStory');
  if (!story) return;

  const slides = Array.from(story.querySelectorAll('[data-pizza-slide]'));
  const stage = story.querySelector('.pizza-story-stage');
  const hero = story.querySelector('.pizza-story-hero');
  const blackout = story.querySelector('.pizza-story-blackout');
  const progress = story.querySelector('.pizza-story-progress');
  const progressBars = progress ? Array.from(progress.children) : [];
  if (!slides.length || !stage || !hero || !blackout) return;

  const clamp = (value, min = 0, max = 1) => Math.min(max, Math.max(min, value));
  const smoothstep = (edge0, edge1, value) => {
    const x = clamp((value - edge0) / Math.max(0.0001, edge1 - edge0));
    return x * x * (3 - 2 * x);
  };

  story.style.setProperty('--pizza-count', String(slides.length));

  slides.forEach(slide => {
    const image = slide.querySelector('.pizza-story-image');
    if (!image) return;
    image.addEventListener('error', () => {
      const fallback = image.dataset.fallback || '';
      if (fallback && image.src !== new URL(fallback, document.baseURI).href) {
        image.src = fallback;
        return;
      }
      image.classList.add('image-missing');
    }, {once: true});
  });

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  if (reducedMotion.matches) {
    story.classList.add('is-reduced-motion');
    slides.forEach(slide => slide.classList.add('is-active'));
    return;
  }

  story.classList.add('is-scroll-ready');

  let ticking = false;

  const render = () => {
    ticking = false;
    const viewport = Math.max(1, window.innerHeight);
    const rect = story.getBoundingClientRect();
    const travel = Math.max(1, story.offsetHeight - viewport);
    const pageProgress = clamp(-rect.top / travel);

    const introEnd = 0.14;
    const outroStart = 0.90;
    const heroFade = smoothstep(0.035, introEnd, pageProgress);
    const outroFade = smoothstep(outroStart, 0.992, pageProgress);
    const stageOpacity = smoothstep(0.095, introEnd, pageProgress) * (1 - outroFade);
    const blackoutOpacity = smoothstep(0.02, introEnd, pageProgress) * (1 - smoothstep(0.925, 0.998, pageProgress));

    hero.style.opacity = String(1 - heroFade);
    hero.style.transform = `scale(${1 + heroFade * 0.025})`;
    blackout.style.opacity = String(blackoutOpacity);
    stage.style.opacity = String(stageOpacity);
    if (progress) progress.style.opacity = String(stageOpacity * 0.9);
    story.classList.toggle('is-ending', pageProgress >= outroStart);

    const storyProgress = clamp((pageProgress - introEnd) / Math.max(0.001, outroStart - introEnd));
    const scaled = Math.min(slides.length - 0.000001, storyProgress * slides.length);
    const activeIndex = Math.min(slides.length - 1, Math.floor(scaled));
    const local = clamp(scaled - activeIndex);

    slides.forEach((slide, index) => {
      const image = slide.querySelector('.pizza-story-image');
      const copy = slide.querySelector('.pizza-story-copy');
      const isActive = index === activeIndex;
      const isPrevious = index === activeIndex - 1 && local < 0.24;

      slide.classList.toggle('is-active', isActive || isPrevious);

      if (isActive) {
        const entry = smoothstep(0.01, 0.30, local);
        const exit = index === slides.length - 1 ? 1 : 1 - smoothstep(0.82, 0.995, local);
        const opacity = entry * exit;
        const copyEntry = smoothstep(0.22, 0.50, local);
        const copyExit = index === slides.length - 1 ? 1 : 1 - smoothstep(0.76, 0.98, local);
        const x = (1 - entry) * 112;
        const rotate = (1 - entry) * 190;
        const scale = 0.88 + entry * 0.12;

        slide.style.opacity = String(opacity);
        if (image) {
          image.style.opacity = String(opacity);
          image.style.transform = `translate3d(${x}vw,0,0) rotate(${rotate}deg) scale(${scale})`;
        }
        if (copy) {
          copy.style.opacity = String(copyEntry * copyExit);
          copy.style.transform = window.innerWidth <= 700
            ? `translate3d(0,${(1 - copyEntry) * 22}px,0)`
            : `translate3d(${(1 - copyEntry) * 34}px,-50%,0)`;
        }
      } else if (isPrevious) {
        const fade = 1 - smoothstep(0, 0.24, local);
        slide.style.opacity = String(fade);
        if (image) {
          image.style.opacity = String(fade);
          image.style.transform = 'translate3d(0,0,0) rotate(0deg) scale(1)';
        }
        if (copy) {
          copy.style.opacity = String(fade);
          copy.style.transform = window.innerWidth <= 700
            ? 'translate3d(0,0,0)'
            : 'translate3d(0,-50%,0)';
        }
      } else {
        slide.style.opacity = '0';
        if (image) {
          image.style.opacity = '0';
          image.style.transform = 'translate3d(112vw,0,0) rotate(190deg) scale(.88)';
        }
        if (copy) copy.style.opacity = '0';
      }
    });

    progressBars.forEach((bar, index) => {
      bar.classList.toggle('is-past', index < activeIndex);
      bar.classList.toggle('is-active', index === activeIndex);
    });
  };

  const requestRender = () => {
    if (ticking) return;
    ticking = true;
    window.requestAnimationFrame(render);
  };

  window.addEventListener('scroll', requestRender, {passive: true});
  window.addEventListener('resize', requestRender, {passive: true});
  render();
})();
