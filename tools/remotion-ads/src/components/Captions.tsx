import React, {useMemo} from 'react';
import {AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import type {CaptionTheme} from '../styles';
import type {WordTiming} from '../types';

const MAX_WORDS = 4;
const MAX_CHARS = 18;

type Group = {words: WordTiming[]; start: number; end: number};

type Look = {
  fontSize: number;
  fontFamily: string;
  weight: number;
  letterSpacing: string;
  textTransform: 'uppercase' | 'none';
  cardRadius: number;
  cardPadding: string;
  cardBackground: string;
  cardBorder: string;
  cardShadow: string;
  idleColor: string;
  idleShadow: string;
  activeColor: string;
  activeBackground: string;
  activePadding: string;
  progress: boolean;
  accentBar: 'top' | 'left' | 'none';
};

function lookFor(theme: CaptionTheme, accent: string): Look {
  const base: Look = {
    fontSize: 54,
    fontFamily: 'Arial, sans-serif',
    weight: 800,
    letterSpacing: '-0.025em',
    textTransform: 'uppercase',
    cardRadius: 26,
    cardPadding: '24px 30px 27px',
    cardBackground: 'linear-gradient(135deg, rgba(10,15,25,0.91), rgba(22,27,38,0.78))',
    cardBorder: '1px solid rgba(255,255,255,0.22)',
    cardShadow: '0 18px 50px rgba(0,0,0,0.48), inset 0 1px 0 rgba(255,255,255,0.12)',
    idleColor: '#FFFFFF',
    idleShadow: '0 2px 10px rgba(0,0,0,0.36)',
    activeColor: '#171717',
    activeBackground: accent,
    activePadding: '2px 7px 4px',
    progress: true,
    accentBar: 'top',
  };

  if (theme === 'pill_accent') {
    return {
      ...base,
      fontSize: 46,
      cardRadius: 999,
      cardPadding: '20px 34px 22px',
      cardBackground: 'rgba(8,12,22,0.82)',
      cardBorder: `1px solid ${accent}66`,
      activeBackground: accent,
      activeColor: '#FFFFFF',
      activePadding: '4px 14px 6px',
      accentBar: 'none',
    };
  }

  if (theme === 'light_card') {
    return {
      ...base,
      fontSize: 48,
      cardRadius: 20,
      cardPadding: '24px 32px 28px',
      cardBackground: 'rgba(255,255,255,0.96)',
      cardBorder: '1px solid rgba(15,23,42,0.08)',
      cardShadow: '0 18px 44px rgba(15,23,42,0.18)',
      idleColor: '#0F172A',
      idleShadow: 'none',
      activeColor: '#FFFFFF',
      activeBackground: accent,
      progress: false,
      accentBar: 'left',
    };
  }

  if (theme === 'minimal_line') {
    return {
      ...base,
      fontSize: 50,
      weight: 700,
      letterSpacing: '0.06em',
      cardRadius: 0,
      cardPadding: '10px 0 0',
      cardBackground: 'transparent',
      cardBorder: 'none',
      cardShadow: 'none',
      idleColor: '#FFFFFF',
      idleShadow: '0 4px 18px rgba(0,0,0,0.55)',
      activeColor: accent,
      activeBackground: 'transparent',
      activePadding: '0 4px 2px',
      progress: false,
      accentBar: 'none',
    };
  }

  if (theme === 'mono_stat') {
    return {
      ...base,
      fontSize: 44,
      fontFamily: '"JetBrains Mono", "Fira Code", ui-monospace, monospace',
      weight: 700,
      letterSpacing: '0.01em',
      cardRadius: 14,
      cardPadding: '22px 28px 24px',
      cardBackground: 'rgba(255,255,255,0.95)',
      cardBorder: '1px solid rgba(15,23,42,0.1)',
      cardShadow: '0 14px 34px rgba(15,23,42,0.16)',
      idleColor: '#0F172A',
      idleShadow: 'none',
      activeColor: accent,
      activeBackground: `${accent}1F`,
      activePadding: '2px 8px 4px',
      progress: true,
      accentBar: 'left',
    };
  }

  return base;
}

function groupWords(words: WordTiming[]): Group[] {
  const groups: Group[] = [];
  let buf: WordTiming[] = [];
  let chars = 0;

  const flush = () => {
    if (!buf.length) return;
    groups.push({words: buf, start: buf[0].inicio, end: buf[buf.length - 1].fin});
    buf = [];
    chars = 0;
  };

  for (const word of words) {
    const next = chars + word.texto.length + (buf.length ? 1 : 0);
    if (buf.length >= MAX_WORDS || (buf.length > 0 && next > MAX_CHARS)) flush();
    buf.push(word);
    chars += word.texto.length + (buf.length > 1 ? 1 : 0);
  }
  flush();
  return groups;
}

export const Captions: React.FC<{
  words: WordTiming[];
  theme?: CaptionTheme;
  accent?: string;
}> = ({words, theme = 'bold_karaoke', accent = '#FF7A00'}) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();
  const seconds = frame / fps;
  const groups = useMemo(() => groupWords(words), [words]);
  const look = useMemo(() => lookFor(theme, accent), [theme, accent]);
  const active = groups.find((group) => seconds >= group.start && seconds < group.end + 0.17);

  if (!active) return null;

  const groupStartFrame = Math.round(active.start * fps);
  const groupEndFrame = Math.round(active.end * fps);
  const groupAge = Math.max(0, frame - groupStartFrame);
  const intro = spring({
    frame: groupAge,
    fps,
    config: {damping: 17, stiffness: 155, mass: 0.48},
  });
  const exit = interpolate(frame, [groupEndFrame, groupEndFrame + 5], [1, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  const progressWidth = interpolate(
    frame,
    [groupStartFrame, Math.max(groupStartFrame + 1, groupEndFrame)],
    [14, 100],
    {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'},
  );

  return (
    <AbsoluteFill
      style={{
        justifyContent: 'flex-end',
        alignItems: 'center',
        paddingBottom: 220,
        paddingLeft: 48,
        paddingRight: 48,
        pointerEvents: 'none',
      }}
    >
      <div
        style={{
          position: 'relative',
          display: 'flex',
          flexWrap: 'wrap',
          justifyContent: 'center',
          gap: '7px 9px',
          maxWidth: 920,
          padding: look.cardPadding,
          borderRadius: look.cardRadius,
          border: look.cardBorder,
          background: look.cardBackground,
          boxShadow: look.cardShadow,
          opacity: Math.min(1, intro) * exit,
          scale: interpolate(intro, [0, 1], [0.91, 1]),
          translate: `0px ${interpolate(intro, [0, 1], [30, 0])}px`,
        }}
      >
        {look.progress && look.accentBar === 'top' ? (
          <div
            style={{
              position: 'absolute',
              top: 0,
              left: 24,
              right: 24,
              height: 4,
              borderRadius: '0 0 8px 8px',
              background: `linear-gradient(90deg, ${accent}, rgba(255,255,255,0.25) 90%)`,
              width: `${progressWidth}%`,
              boxShadow: `0 0 15px ${accent}88`,
            }}
          />
        ) : null}
        {look.accentBar === 'left' ? (
          <div
            style={{
              position: 'absolute',
              left: 0,
              top: 18,
              bottom: 18,
              width: 5,
              borderRadius: 8,
              background: accent,
              boxShadow: `0 0 18px ${accent}88`,
            }}
          />
        ) : null}
        {active.words.map((word, index) => {
          const wordStartFrame = Math.round(word.inicio * fps);
          const wordOn = seconds >= word.inicio && seconds < word.fin + 0.05;
          const wordSpring = spring({
            frame: Math.max(0, frame - wordStartFrame),
            fps,
            config: {damping: 13, stiffness: 210, mass: 0.36},
          });

          return (
            <span
              key={`${word.inicio}-${index}`}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                borderRadius: theme === 'pill_accent' ? 999 : 9,
                padding: look.activePadding,
                fontFamily: look.fontFamily,
                fontWeight: look.weight,
                fontSize: look.fontSize,
                lineHeight: 1.12,
                letterSpacing: look.letterSpacing,
                textTransform: look.textTransform,
                color: wordOn ? look.activeColor : look.idleColor,
                backgroundColor: wordOn ? look.activeBackground : 'transparent',
                textShadow: wordOn ? 'none' : look.idleShadow,
                scale: wordOn ? interpolate(wordSpring, [0, 1], [0.88, 1.04]) : 1,
                translate: wordOn ? `0px ${interpolate(wordSpring, [0, 1], [9, 0])}px` : '0px 0px',
              }}
            >
              {word.texto}
            </span>
          );
        })}
      </div>
    </AbsoluteFill>
  );
};
