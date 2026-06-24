(function () {
  var celebrationEmojis = ['🦄', '⭐', '✨', '🌟', '💫', '🎉', '🥳', '👏', '💚', '🎊', '✅', '🌈', '🔥', '💎', '🍀'];
  var celebrationColors = ['#007017', '#72aee6', '#dba617', '#d63638', '#9b51e0', '#00a32a'];

  function pickCelebrationStyle() {
    var optionCount = celebrationEmojis.length + 1;

    if (Math.floor(Math.random() * optionCount) === 0) {
      return { type: 'confetti' };
    }

    return {
      type: 'emoji',
      value: celebrationEmojis[Math.floor(Math.random() * celebrationEmojis.length)],
    };
  }

  function createParticles(originX, originY, style) {
    var particles = [];
    var count = 36;

    for (var i = 0; i < count; i++) {
      var angle = Math.random() * Math.PI * 2;
      var speed = 7 + Math.random() * 11;
      var particle = {
        x: originX + (Math.random() - 0.5) * 12,
        y: originY + (Math.random() - 0.5) * 8,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed - (6 + Math.random() * 8),
        rotation: Math.random() * 360,
        spin: (Math.random() - 0.5) * 14,
        opacity: 1,
      };

      if (style.type === 'emoji') {
        particle.emoji = style.value;
        particle.size = 22 + Math.random() * 8;
      } else {
        particle.w = 8 + Math.random() * 6;
        particle.h = 12 + Math.random() * 8;
        particle.color = celebrationColors[Math.floor(Math.random() * celebrationColors.length)];
      }

      particles.push(particle);
    }

    return particles;
  }

  function drawParticle(ctx, particle, style) {
    if (particle.opacity <= 0) {
      return;
    }

    ctx.save();
    ctx.globalAlpha = particle.opacity;
    ctx.translate(particle.x, particle.y);
    ctx.rotate((particle.rotation * Math.PI) / 180);

    if (style.type === 'emoji') {
      ctx.font = particle.size + 'px serif';
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(particle.emoji, 0, 0);
    } else {
      ctx.fillStyle = particle.color;
      ctx.fillRect(-particle.w / 2, -particle.h / 2, particle.w, particle.h);
    }

    ctx.restore();
  }

  function celebrateProofread(anchor) {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return;
    }

    var rect = anchor.getBoundingClientRect();
    var originX = rect.left + rect.width / 2;
    var originY = rect.top + rect.height / 2;
    var style = pickCelebrationStyle();
    var particles = createParticles(originX, originY, style);

    var canvas = document.createElement('canvas');
    canvas.className = 'gds-content-translation__celebration-canvas';
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    document.body.appendChild(canvas);

    var ctx = canvas.getContext('2d');
    var start = performance.now();
    var duration = 2200;
    var gravity = 0.38;
    var drag = 0.988;

    function frame(now) {
      var elapsed = now - start;
      var alive = false;

      ctx.clearRect(0, 0, canvas.width, canvas.height);

      for (var i = 0; i < particles.length; i++) {
        var particle = particles[i];

        particle.vx *= drag;
        particle.vy += gravity;
        particle.x += particle.vx;
        particle.y += particle.vy;
        particle.rotation += particle.spin;

        if (elapsed > duration * 0.55) {
          particle.opacity = Math.max(
            0,
            1 - (elapsed - duration * 0.55) / (duration * 0.45)
          );
        }

        if (
          particle.opacity > 0 &&
          particle.y > -40 &&
          particle.y < canvas.height + 60 &&
          particle.x > -40 &&
          particle.x < canvas.width + 40
        ) {
          alive = true;
          drawParticle(ctx, particle, style);
        }
      }

      if (alive && elapsed < duration) {
        requestAnimationFrame(frame);
      } else {
        canvas.remove();
      }
    }

    requestAnimationFrame(frame);
  }

  document.addEventListener('change', function (event) {
    var target = event.target;

    if (!target.classList.contains('gds-content-translation__proofread-input')) {
      return;
    }

    var postId = target.dataset.postId;

    if (!postId || !window.contentTranslationStatus) {
      return;
    }

    target.classList.add('is-saving');

    var body = new FormData();
    body.append('action', 'gds_ct_save_proofread');
    body.append('nonce', contentTranslationStatus.nonce);
    body.append('postId', postId);
    body.append('proofread', target.checked ? '1' : '0');

    fetch(contentTranslationStatus.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: body,
    })
      .then(function (response) {
        return response.json();
      })
      .then(function (payload) {
        if (!payload.success) {
          target.checked = !target.checked;
          return;
        }

        if (target.checked) {
          celebrateProofread(target);
        }
      })
      .catch(function () {
        target.checked = !target.checked;
      })
      .finally(function () {
        target.classList.remove('is-saving');
      });
  });
})();
