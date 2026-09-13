import React from 'react';
import {
  AbsoluteFill,
  Img,
  interpolate,
  OffthreadVideo,
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
};

export const MediaSlide: React.FC<Props> = ({clip, preset}) => {
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
          ken === 'in' ? [1, 1.12] : [1.12, 1],
          {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'},
        );

  let opacity = 1;
  let finalScale = scale;
  const transition = isVideo ? 'jump_cut' : clip.transition;
  if (transition === 'fade') {
    const fadeIn = Math.min(8, Math.floor(span / 4));
    opacity = interpolate(local, [0, fadeIn], [0, 1], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
    });
  } else if (transition === 'zoom_in') {
    const boost = interpolate(local, [0, Math.min(12, span)], [1.08, 1], {
      extrapolateLeft: 'clamp',
      extrapolateRight: 'clamp',
    });
    finalScale = scale * boost;
  }

  return (
    <AbsoluteFill style={{opacity: isVideo ? 1 : opacity}}>
      <MediaInner clip={clip} scale={isVideo ? 1 : finalScale} />
    </AbsoluteFill>
  );
};

const MediaInner: React.FC<{clip: ClipPlan; scale: number}> = ({
  clip,
  scale,
}) => {
  const src = resolveSrc(clip.media);

  if (clip.media_type === 'video') {
    return (
      <AbsoluteFill>
        <OffthreadVideo
          src={src}
          muted
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
        transform: `scale(${scale})`,
      }}
    />
  );
};
