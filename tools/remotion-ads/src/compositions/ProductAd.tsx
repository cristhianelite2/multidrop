import React, {useMemo} from 'react';
import {
  AbsoluteFill,
  Audio,
  Sequence,
  interpolate,
  staticFile,
  useCurrentFrame,
  useVideoConfig,
  spring,
} from 'remotion';
import {TransitionSeries, linearTiming, type TransitionPresentation} from '@remotion/transitions';
import {fade} from '@remotion/transitions/fade';
import {slide} from '@remotion/transitions/slide';
import {wipe} from '@remotion/transitions/wipe';
import {Captions} from '../components/Captions';
import {AnimatedElements} from '../components/AnimatedElements';
import {CutTransition, type CutTransitionStyle} from '../components/CutTransition';
import {MediaSlide} from '../components/MediaSlide';
import {StyleChrome} from '../components/StyleChrome';
import {resolveProductStyle} from '../styles';
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
  preset: 'social_ad',
  styleId: 'social_ad',
  voiceSrc: '',
  musicSrc: null,
  musicVolume: 0.3,
  words: [],
  clips: [],
  durationInSeconds: 5,
  ctaText: '',
};

export const ProductAd: React.FC<ProductAdProps> = (props) => {
  const {
    preset: presetPedido,
    styleId,
    style: styleProps,
    voiceSrc,
    musicSrc,
    musicVolume = 0.3,
    words,
    clips,
    ctaText,
  } = props;
  const frame = useCurrentFrame();
  const {fps, durationInFrames} = useVideoConfig();
  const style = useMemo(
    () => ({
      ...resolveProductStyle(styleId ?? presetPedido),
      ...(styleProps ?? {}),
    }),
    [styleId, presetPedido, styleProps],
  );
  const preset = style.preset_base;
  const orderedClips = [...clips].sort((a, b) => a.start_s - b.start_s);
  const canUseTransitionSeries =
    orderedClips.length > 1 &&
    Math.abs(orderedClips[0].start_s) <= 0.02 &&
    orderedClips.slice(1).every((clip, index) =>
      Math.abs(clip.start_s - orderedClips[index].end_s) <= 0.02,
    );

  const activeClip = orderedClips.find(
    (clip) => frame >= clip.start_s * fps && frame < clip.end_s * fps,
  );
  const badgeText = (activeClip?.text_on_screen ?? '').trim();
  const highlights = orderedClips
    .map((clip) => (clip.text_on_screen ?? '').trim())
    .filter((text, index, all) => text !== '' && all.indexOf(text) === index)
    .slice(0, 3);

  const ctaStartFrame = durationInFrames - Math.round(fps * 2.2);
  const showCta = Boolean(ctaText) && frame >= ctaStartFrame;
  const ctaSpring = spring({
    frame: Math.max(0, frame - ctaStartFrame),
    fps,
    config: {damping: 15, stiffness: 150, mass: 0.55},
  });

  const voice = resolveSrc(voiceSrc);
  const music = resolveSrc(musicSrc);

  const transitionFrames = Math.max(8, Math.round(fps * 0.42));
  const seriesChildren: React.ReactNode[] = [];
  orderedClips.forEach((clip, index) => {
    const next = orderedClips[index + 1];
    const canTransition =
      canUseTransitionSeries &&
      Boolean(next) &&
      clip.media_type === 'image' &&
      next?.media_type === 'image';
    const duration = Math.max(1, Math.round((clip.end_s - clip.start_s) * fps));

    seriesChildren.push(
      <TransitionSeries.Sequence
        key={`scene-${index}-${clip.media}`}
        durationInFrames={duration + (canTransition ? transitionFrames : 0)}
      >
        <MediaSlide clip={clip} preset={preset} transitionHandledExternally />
      </TransitionSeries.Sequence>,
    );

    if (canTransition && next) {
      seriesChildren.push(
        <TransitionSeries.Transition
          key={`transition-${index}`}
          presentation={transitionPresentation(next.transition, index)}
          timing={linearTiming({durationInFrames: transitionFrames})}
        />,
      );
    }
  });

  return (
    <AbsoluteFill style={{backgroundColor: style.background}}>
      {canUseTransitionSeries ? (
        <TransitionSeries>{seriesChildren}</TransitionSeries>
      ) : (
        orderedClips.map((clip, i) => {
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
        })
      )}

      {!canUseTransitionSeries
        ? orderedClips.slice(1).map((clip, index) => {
            const previous = orderedClips[index];
            if (previous?.media_type !== 'image' || clip.media_type !== 'image') return null;
            const boundary = Math.round(clip.start_s * fps);
            const from = Math.max(0, boundary - 9);
            const duration = Math.min(18, durationInFrames - from);
            if (boundary <= 0 || duration <= 0) return null;
            return (
              <Sequence
                key={`graphic-cut-${clip.start_s}-${index}`}
                from={from}
                durationInFrames={duration}
              >
                <CutTransition style={cutStyle(clip.transition, index)} index={index} />
              </Sequence>
            );
          })
        : null}

      {style.decorations.includes('spark') ? (
        <AnimatedElements preset={preset} energy={style.energy} />
      ) : null}

      <StyleChrome
        style={style}
        ctaText={ctaText ?? ''}
        ctaVisible={showCta}
        ctaProgress={Math.min(1, ctaSpring)}
        badgeText={badgeText}
        highlights={highlights}
      />

      <Captions words={words} theme={style.captions} accent={style.accent} />

      {voice ? <Audio src={voice} /> : null}
      {music ? (
        <Audio
          src={music}
          loop
          volume={(audioFrame) => {
            // La biblioteca está normalizada a -18 LUFS; este envolvente la deja
            // como base audible bajo la voz y la cierra con fundidos limpios.
            const fadeIn = Math.max(1, Math.round(fps * 0.8));
            const fadeOut = Math.max(1, Math.round(fps * 1.5));
            return interpolate(
              audioFrame,
              [0, fadeIn, Math.max(fadeIn, durationInFrames - fadeOut), durationInFrames],
              [0, musicVolume, musicVolume, 0],
              {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'},
            );
          }}
        />
      ) : null}
    </AbsoluteFill>
  );
};

function cutStyle(transition: string | undefined, index: number): CutTransitionStyle {
  if (transition === 'fade' || transition === 'slide_left' || transition === 'slide_right' || transition === 'zoom_in') {
    return transition;
  }
  if (transition === 'random') {
    return (['wipe', 'slide_left', 'zoom_in', 'fade'] as const)[index % 4];
  }
  return 'wipe';
}

function transitionPresentation(transition: string | undefined, index: number): TransitionPresentation<any> {
  if (transition === 'slide_left') return slide({direction: 'from-right'});
  if (transition === 'slide_right') return slide({direction: 'from-left'});
  if (transition === 'wipe') return wipe({direction: index % 2 === 0 ? 'from-left' : 'from-top-right'});
  if (transition === 'random') {
    const styles = ['wipe', 'slide_left', 'fade'] as const;
    return transitionPresentation(styles[index % styles.length], index);
  }
  // The incoming still keeps its own Ken Burns / zoom entrance. Use a dissolve
  // between scenes so that motion and transition remain complementary.
  if (transition === 'zoom_in') return fade();
  // Legacy jump_cut plans get a quick, restrained dissolve so old plans animate too.
  return fade();
}
