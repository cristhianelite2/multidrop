import React from 'react';
import {
  AbsoluteFill,
  Audio,
  Sequence,
  interpolate,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
} from 'remotion';
import {Captions} from '../components/Captions';
import {MediaSlide} from '../components/MediaSlide';
import type {ProductAdProps} from '../types';

function resolveSrc(src: string | null | undefined): string | null {
  if (!src) return null;
  if (
    src.startsWith('http://') ||
    src.startsWith('https://') ||
    src.startsWith('data:')
  ) {
    return src;
  }
  return staticFile(src.replace(/^\//, ''));
}

export const defaultProductAdProps: ProductAdProps = {
  preset: 'product_presenter',
  voiceSrc: '',
  musicSrc: null,
  musicVolume: 0.12,
  words: [],
  clips: [],
  durationInSeconds: 5,
  ctaText: '',
};

export const ProductAd: React.FC<ProductAdProps> = (props) => {
  const {
    preset,
    voiceSrc,
    musicSrc,
    musicVolume = 0.12,
    words,
    clips,
    ctaText,
  } = props;
  const frame = useCurrentFrame();
  const {fps, durationInFrames} = useVideoConfig();

  const vignette =
    preset === 'product_presenter'
      ? 'radial-gradient(ellipse at center, transparent 40%, rgba(0,0,0,0.45) 100%)'
      : 'linear-gradient(180deg, rgba(0,0,0,0.25) 0%, transparent 25%, transparent 55%, rgba(0,0,0,0.65) 100%)';

  const showCta =
    Boolean(ctaText) && frame > durationInFrames - Math.round(fps * 2.2);

  const voice = resolveSrc(voiceSrc);
  const music = resolveSrc(musicSrc);

  return (
    <AbsoluteFill style={{backgroundColor: '#0a0a0a'}}>
      {clips.map((clip, i) => {
        const from = Math.max(0, Math.round(clip.start_s * fps));
        const to = Math.round(clip.end_s * fps);
        const dur = Math.max(1, to - from);
        return (
          <Sequence
            key={`${clip.media}-${i}`}
            from={from}
            durationInFrames={dur}
          >
            <MediaSlide clip={clip} preset={preset} />
          </Sequence>
        );
      })}

      <AbsoluteFill
        style={{backgroundImage: vignette, pointerEvents: 'none'}}
      />

      <Captions words={words} />

      {showCta ? (
        <AbsoluteFill
          style={{
            justifyContent: 'flex-end',
            alignItems: 'center',
            paddingBottom: 120,
          }}
        >
          <div
            style={{
              backgroundColor: '#FF7A00',
              color: '#111',
              fontFamily: 'Montserrat, Arial Black, sans-serif',
              fontWeight: 800,
              fontSize: 42,
              padding: '18px 40px',
              borderRadius: 999,
              opacity: interpolate(
                frame,
                [
                  durationInFrames - Math.round(fps * 2.2),
                  durationInFrames - Math.round(fps * 1.6),
                ],
                [0, 1],
                {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'},
              ),
            }}
          >
            {ctaText}
          </div>
        </AbsoluteFill>
      ) : null}

      {voice ? <Audio src={voice} /> : null}
      {music ? <Audio src={music} volume={musicVolume} /> : null}
    </AbsoluteFill>
  );
};
