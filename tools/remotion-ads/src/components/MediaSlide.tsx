import React from 'react';
import {
  AbsoluteFill,
  Img,
  interpolate,
  OffthreadVideo,
  Easing,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion';
import type {ClipPlan} from '../types';

function resolveSrc(src: string): string {
  if (!src) return src;
  if (
    src.startsWith('http://') ||
    src.startsWith('https://') ||
    src.startsWith('data:')
  ) {
    return src;
  }
  return staticFile(src.replace(/^\//, ''));
}

type Props = {
  clip: ClipPlan;
  preset: 'product_presenter' | 'quick_transition';
  transitionHandledExternally?: boolean;
};

export const MediaSlide: React.FC<Props> = ({clip, preset, transitionHandledExternally = false}) => {
  // Dentro de <Sequence>, useCurrentFrame() ya es relativo al clip (empieza en 0).
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();
  const span = Math.max(
    1,
    Math.round((Math.max(clip.end_s, clip.start_s) - clip.start_s) * fps),
  );
  const local = Math.max(0, frame);
  const isVideo = clip.media_type === 'video';

  // En videos no aplicar fade/ken-burns: OffthreadVideo + opacity/transform suele verse negro.
  const ken =
    isVideo || clip.ken_burns === 'none'
      ? 'none'
      : (clip.ken_burns ?? (preset === 'product_presenter' ? 'in' : 'out'));
  const scale =
    ken === 'none'
      ? 1
      : interpolate(
          local,
          [0, span],
          ken === 'in' ? [1, 1.14] : [1.14, 1],
          {
            extrapolateLeft: 'clamp',
            extrapolateRight: 'clamp',
            easing: Easing.bezier(0.16, 1, 0.3, 1),
          },
        );

  let opacity = 1;
  let finalScale = scale;
  let translateX = 0;
  let translateY = 0;
  let clipPath: string | undefined;

  // Transiciones aleatorias o específicas entre imágenes
  let resolvedTransition: NonNullable<ClipPlan['transition']> = isVideo
    ? 'jump_cut'
    : clip.transition ?? 'jump_cut';
  if (resolvedTransition === 'random') {
    const options = ['fade', 'zoom_in', 'slide_left', 'slide_right', 'wipe'] as const;
    // Usar el start_s o un hash básico del media para que sea determinista por clip
    const hash = Math.abs(Math.sin(clip.start_s * 999)) * options.length;
    resolvedTransition = options[Math.floor(hash)];
  }

  if (transitionHandledExternally && !isVideo) {
    // TransitionSeries owns the scene boundary; retain an intentional zoom
    // entrance on the still itself without stacking local fades or wipes.
    resolvedTransition = resolvedTransition === 'zoom_in' ? 'zoom_in' : 'jump_cut';
  }

  if (!isVideo && ken !== 'none') {
    translateX += interpolate(local, [0, span], [14, -14], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
      easing: Easing.bezier(0.16, 1, 0.3, 1),
    });
    translateY += interpolate(local, [0, span], [-10, 10], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
      easing: Easing.bezier(0.16, 1, 0.3, 1),
    });
  }

  if (resolvedTransition === 'fade') {
    const fadeIn = Math.min(8, Math.floor(span / 4));
    opacity = interpolate(local, [0, fadeIn], [0, 1], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
    });
  } else if (resolvedTransition === 'zoom_in') {
    const boost = interpolate(local, [0, Math.min(12, span)], [1.08, 1], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
    });
    finalScale = scale * boost;
  } else if (resolvedTransition === 'slide_left') {
    translateX = interpolate(local, [0, Math.min(15, span)], [40, 0], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
    });
    const fadeIn = Math.min(10, Math.floor(span / 4));
    opacity = interpolate(local, [0, fadeIn], [0, 1], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
    });
  } else if (resolvedTransition === 'slide_right') {
    translateX = interpolate(local, [0, Math.min(15, span)], [-40, 0], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
    });
    const fadeIn = Math.min(10, Math.floor(span / 4));
    opacity = interpolate(local, [0, fadeIn], [0, 1], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
    });
  } else if (resolvedTransition === 'wipe') {
    const reveal = interpolate(local, [0, Math.min(18, span)], [100, 0], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
    });
    clipPath = `inset(0 ${reveal}% 0 0)`;
  }

  return (
    <AbsoluteFill style={{opacity: isVideo ? 1 : opacity, clipPath: isVideo ? undefined : clipPath}}>
      <MediaInner clip={clip} scale={isVideo ? 1 : finalScale} translateX={translateX} translateY={translateY} />
    </AbsoluteFill>
  );
};

const MediaInner: React.FC<{clip: ClipPlan; scale: number; translateX: number; translateY: number}> = ({
  clip,
  scale,
  translateX,
  translateY,
}) => {
  const src = resolveSrc(clip.media);

  if (clip.media_type === 'video') {
    return (
      <AbsoluteFill>
        <OffthreadVideo
          src={src}
          muted
          startFrom={clip.videoStartFrame ?? 0}
          style={{
            width: '100%',
            height: '100%',
            objectFit: 'cover',
          }}
        />
      </AbsoluteFill>
    );
  }

  return (
    <Img
      src={src}
      style={{
        width: '100%',
        height: '100%',
        objectFit: 'cover',
        scale,
        translate: `${translateX}px ${translateY}px`,
      }}
    />
  );
};
