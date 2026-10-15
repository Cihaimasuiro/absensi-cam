---
name: modern-web-animations
description: Master modern web animations, smooth scrolling, and micro-interactions using GSAP, Lenis, and React Bits component patterns. Use when designing premium landing pages, smooth page transitions, interactive canvas effects, and component animations.
---

# Modern Web Animations & Micro-Interactions (GSAP, Lenis, React Bits)

## Overview

High-end, fluid web experiences rely on three foundational pillars:
1. **Lenis (`lenis`)**: Hardware-accelerated, native-feeling smooth scroll engine without hijacking browser mechanics.
2. **GSAP (`gsap`)**: The industry standard for high-performance timeline orchestration, SVG transforms, and ScrollTrigger interactions.
3. **React Bits (`reactbits.dev`)**: Modular, customizable component patterns for particle canvases, aurora backgrounds, text reveals, and interactive cards.

---

## 1. Lenis Smooth Scrolling

### Basic Setup
```javascript
import Lenis from 'lenis'

export function initSmoothScroll() {
  const lenis = new Lenis({
    duration: 1.2,
    easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
    orientation: 'vertical',
    smoothWheel: true,
  })

  function raf(time) {
    lenis.raf(time)
    requestAnimationFrame(raf)
  }

  requestAnimationFrame(raf)
  return lenis
}
```

### Synchronizing Lenis with GSAP ScrollTrigger
```javascript
import Lenis from 'lenis'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

gsap.registerPlugin(ScrollTrigger)

const lenis = new Lenis()

// Sync ScrollTrigger with Lenis scroll position
lenis.on('scroll', ScrollTrigger.update)

// Drive Lenis from GSAP ticker for a single unified render loop
gsap.ticker.add((time) => {
  lenis.raf(time * 1000)
})
gsap.ticker.lagSmoothing(0)
```

---

## 2. GSAP Core Patterns

### Micro-Interactions & Hover Dynamics
```javascript
import gsap from 'gsap'

export function attachCardHover(selector) {
  document.querySelectorAll(selector).forEach(card => {
    card.addEventListener('mouseenter', () => {
      gsap.to(card, {
        scale: 1.02,
        y: -4,
        boxShadow: '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1)',
        duration: 0.3,
        ease: 'power2.out'
      })
    })

    card.addEventListener('mouseleave', () => {
      gsap.to(card, {
        scale: 1,
        y: 0,
        boxShadow: '0 1px 3px 0 rgba(0, 0, 0, 0.1)',
        duration: 0.3,
        ease: 'power2.out'
      })
    })
  })
}
```

---

## 3. React Bits Component Patterns

React Bits components are modular and can be used in React or adapted to Alpine.js / Vanilla JS.

### Pattern A: Spotlight Card (Cursor Tracking Light Effect)
```javascript
export function initSpotlightCards() {
  document.querySelectorAll('.spotlight-card').forEach(card => {
    card.addEventListener('mousemove', (e) => {
      const rect = card.getBoundingClientRect();
      const x = e.clientX - rect.left;
      const y = e.clientY - rect.top;
      card.style.setProperty('--mouse-x', `${x}px`);
      card.style.setProperty('--mouse-y', `${y}px`);
    });
  });
}
```

### Pattern B: Blur Text / Split Text Stagger (GSAP + React Bits)
```javascript
export function animateTextReveal(selector) {
  gsap.from(selector, {
    opacity: 0,
    y: 20,
    filter: 'blur(8px)',
    duration: 0.8,
    stagger: 0.05,
    ease: 'power3.out'
  });
}
```

---

## 4. Performance & Quality Standards

1. **Composite-Only Properties**: Animate only `transform` (`x`, `y`, `scale`, `rotation`) and `opacity`.
2. **Reduced Motion Compliance**: Always respect `prefers-reduced-motion`.
3. **Memory Cleanup**: On page transitions / component teardown, call `lenis.destroy()` and `ScrollTrigger.getAll().forEach(t => t.kill())`.
