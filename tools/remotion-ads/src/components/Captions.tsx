import React, {useMemo} from 'react';
import {AbsoluteFill, useCurrentFrame, useVideoConfig} from 'remotion';
import type {WordTiming} from '../types';

const ACCENT = '#FF7A00';
const MAX_WORDS = 4;
const MAX_CHARS = 18;

type Group = {words: WordTiming[]; start: number; end: number};

function groupWords(words: WordTiming[]): Group[] {
  const groups: Group[] = [];
  let buf: WordTiming[] = [];
  let chars = 0;

  const flush = () => {
    if (!buf.length) return;
    groups.push({
      words: buf,
      start: buf[0].inicio,
      end: buf[buf.length - 1].fin,
    });
    buf = [];
    chars = 0;
  };

  for (const w of words) {
    const next = chars + w.texto.length + (buf.length ? 1 : 0);
    if (buf.length >= MAX_WORDS || (buf.length > 0 && next > MAX_CHARS)) {
      flush();
    }
    buf.push(w);
    chars += w.texto.length + (buf.length > 1 ? 1 : 0);
  }
  flush();
  return groups;
}

export const Captions: React.FC<{words: WordTiming[]}> = ({words}) => {
  const frame = useCurrentFrame();
  const {fps} = useVideoConfig();
  const t = frame / fps;
  const groups = useMemo(() => groupWords(words), [words]);

  const active = groups.find((g) => t >= g.start && t < g.end + 0.15);
  if (!active) {
    return null;
  }

  return (
    <AbsoluteFill
      style={{
        justifyContent: 'flex-end',
        alignItems: 'center',
        paddingBottom: 220,
        paddingLeft: 48,
        paddingRight: 48,
      }}
    >
      <div
        style={{
          display: 'flex',
          flexWrap: 'wrap',
          justifyContent: 'center',
          gap: 12,
          maxWidth: 920,
        }}
      >
        {active.words.map((w, i) => {
          const on = t >= w.inicio && t < w.fin + 0.05;
          return (
            <span
              key={`${w.inicio}-${i}`}
              style={{
                fontFamily: 'Montserrat, Arial Black, sans-serif',
                fontWeight: 800,
                fontSize: 54,
                lineHeight: 1.15,
                color: on ? ACCENT : '#FFFFFF',
                textShadow:
                  '0 2px 0 rgba(0,0,0,0.85), 0 6px 18px rgba(0,0,0,0.55)',
                transform: on ? 'scale(1.06)' : 'scale(1)',
                transition: 'none',
              }}
            >
              {w.texto}
            </span>
          );
        })}
      </div>
    </AbsoluteFill>
  );
};
