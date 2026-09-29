import React from 'react';
import {AbsoluteFill, interpolate, useCurrentFrame, useVideoConfig} from 'remotion';
import type {CtaTheme, Overlay, ProductStyle} from '../styles';

type Props = {
  style: ProductStyle;
  ctaText: string;
  ctaVisible: boolean;
  ctaProgress: number;
  badgeText?: string;
  highlights?: string[];
};

const OVERLAYS: Record<Overlay, string> = {
  vignette: 'radial-gradient(ellipse at center, transparent 40%, rgba(0,0,0,0.45) 100%)',
  brand_gradient:
    'linear-gradient(to top, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0.38) 40%, rgba(0,0,0,0.06) 70%), linear-gradient(135deg, rgba(37,99,235,0.22) 0%, transparent 58%)',
  hero_dark:
    'linear-gradient(180deg, rgba(0,0,0,0.55) 0%, transparent 28%, rgba(0,0,0,0.22) 58%, rgba(0,0,0,0.82) 100%)',
  dark_orbs: 'radial-gradient(ellipse at center, transparent 42%, rgba(0,0,0,0.58) 100%)',
  scrim_bottom:
    'linear-gradient(180deg, rgba(0,0,0,0.25) 0%, transparent 25%, transparent 55%, rgba(0,0,0,0.65) 100%)',
  light_card:
    'linear-gradient(to top, rgba(248,250,252,0.96) 0%, rgba(248,250,252,0.72) 26%, rgba(248,250,252,0) 52%)',
  light_cards:
    'linear-gradient(to top, rgba(241,245,249,0.94) 0%, rgba(241,245,249,0.6) 22%, rgba(241,245,249,0) 46%)',
};

const MAT: Partial<Record<Overlay, {size: number; colour: string}>> = {
  light_card: {size: 20, colour: '#F8FAFC'},
  light_cards: {size: 12, colour: '#F1F5F9'},
};

const has = (style: ProductStyle, decoration: string) => style.decorations.includes(decoration as never);

const GradientOrbs: React.FC<{style: ProductStyle}> = ({style}) => {
  const frame = useCurrentFrame();
  const {durationInFrames} = useVideoConfig();
  const dx = interpolate(frame, [0, durationInFrames], [0, 120], {extrapolateRight: 'clamp'});
  const dy = interpolate(frame, [0, durationInFrames], [0, -60], {extrapolateRight: 'clamp'});

  return (
    <>
      <div
        style={{
          position: 'absolute',
          width: 760,
          height: 760,
          borderRadius: '50%',
          background: `radial-gradient(circle, ${style.accent}40 0%, transparent 70%)`,
          top: 180 + dy,
          left: -160 + dx,
          filter: 'blur(70px)',
        }}
      />
      <div
        style={{
          position: 'absolute',
          width: 620,
          height: 620,
          borderRadius: '50%',
          background: `radial-gradient(circle, ${style.accent_2}38 0%, transparent 70%)`,
          bottom: 260 - dy,
          right: -180 + dx,
          filter: 'blur(60px)',
        }}
      />
    </>
  );
};

const GridPattern: React.FC = () => (
  <AbsoluteFill
    style={{
      backgroundImage:
        'linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px)',
      backgroundSize: '46px 46px',
    }}
  />
);

const AccentBar: React.FC<{style: ProductStyle}> = ({style}) => (
  <div style={{position: 'absolute', top: 0, left: 0, right: 0, height: 8, backgroundColor: style.accent}} />
);

const QuoteMark: React.FC<{style: ProductStyle}> = ({style}) => {
  const frame = useCurrentFrame();
  const opacity = interpolate(frame, [0, 20], [0, 0.16], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  return (
    <div
      style={{
        position: 'absolute',
        top: 210,
        left: 64,
        fontSize: 300,
        fontWeight: 900,
        lineHeight: 1,
        fontFamily: 'Georgia, serif',
        color: style.accent,
        opacity,
      }}
    >
      &ldquo;
    </div>
  );
};

const Badge: React.FC<{style: ProductStyle; text: string}> = ({style, text}) => {
  const frame = useCurrentFrame();
  const opacity = interpolate(frame, [4, 18], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });
  if (!text) return null;

  return (
    <div
      style={{
        position: 'absolute',
        top: 74,
        left: 56,
        opacity,
        backgroundColor: style.accent,
        color: '#FFFFFF',
        padding: '10px 18px',
        borderRadius: 6,
        fontFamily: 'Arial, sans-serif',
        fontSize: 26,
        fontWeight: 700,
        letterSpacing: 1.4,
        textTransform: 'uppercase',
        maxWidth: 880,
        overflow: 'hidden',
        whiteSpace: 'nowrap',
        textOverflow: 'ellipsis',
      }}
    >
      {text}
    </div>
  );
};

const StatChip: React.FC<{style: ProductStyle; text: string}> = ({style, text}) => {
  const frame = useCurrentFrame();
  if (!text) return null;
  const enter = interpolate(frame, [10, 24], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  return (
    <div
      style={{
        position: 'absolute',
        top: 132,
        left: 56,
        display: 'inline-flex',
        alignItems: 'center',
        gap: 10,
        padding: '12px 20px',
        borderRadius: 999,
        border: `2px solid ${style.accent}`,
        backgroundColor: 'rgba(15,23,42,0.72)',
        color: style.text,
        fontFamily: 'Arial, sans-serif',
        fontSize: 26,
        fontWeight: 700,
        letterSpacing: 0.6,
        opacity: enter,
        scale: interpolate(enter, [0, 1], [0.86, 1]),
        maxWidth: 820,
        overflow: 'hidden',
        whiteSpace: 'nowrap',
        textOverflow: 'ellipsis',
      }}
    >
      <span style={{width: 12, height: 12, borderRadius: '50%', backgroundColor: style.accent}} />
      {text}
    </div>
  );
};

const StatCards: React.FC<{style: ProductStyle; items: string[]}> = ({style, items}) => {
  const frame = useCurrentFrame();
  if (items.length === 0) return null;

  return (
    <div
      style={{
        position: 'absolute',
        top: 78,
        left: 44,
        right: 44,
        display: 'flex',
        gap: 16,
      }}
    >
      {items.map((item, index) => {
        const delay = 6 + index * 6;
        const enter = interpolate(frame - delay, [0, 12], [0, 1], {
          extrapolateLeft: 'clamp',
          extrapolateRight: 'clamp',
        });
        return (
          <div
            key={`${item}-${index}`}
            style={{
              flex: 1,
              minWidth: 0,
              backgroundColor: 'rgba(255,255,255,0.94)',
              border: `1px solid ${style.accent}33`,
              borderTop: `4px solid ${style.accent}`,
              borderRadius: 12,
              boxShadow: '0 2px 12px rgba(15,23,42,0.12)',
              padding: '16px 18px',
              opacity: enter,
              translate: `0px ${interpolate(enter, [0, 1], [18, 0])}px`,
            }}
          >
            <div
              style={{
                fontFamily: '"JetBrains Mono", "Fira Code", ui-monospace, monospace',
                fontSize: 30,
                fontWeight: 700,
                color: style.accent,
                lineHeight: 1.15,
                overflow: 'hidden',
                whiteSpace: 'nowrap',
                textOverflow: 'ellipsis',
              }}
            >
              {item}
            </div>
            <div
              style={{
                marginTop: 8,
                fontFamily: 'Arial, sans-serif',
                fontSize: 19,
                fontWeight: 600,
                letterSpacing: 1,
                textTransform: 'uppercase',
                color: style.muted,
                overflow: 'hidden',
                whiteSpace: 'nowrap',
                textOverflow: 'ellipsis',
              }}
            >
              Detalle
            </div>
          </div>
        );
      })}
    </div>
  );
};

const UnderlineSweep: React.FC<{style: ProductStyle}> = ({style}) => {
  const frame = useCurrentFrame();
  const width = interpolate(frame, [8, 38], [0, 100], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
  });

  return (
    <div
      style={{
        position: 'absolute',
        top: 168,
        left: 56,
        height: 4,
        width: `${width}%`,
        borderRadius: 2,
        background: `linear-gradient(90deg, ${style.accent}, ${style.accent_2})`,
      }}
    />
  );
};

const Cta: React.FC<{style: ProductStyle; text: string; progress: number}> = ({style, text, progress}) => {
  if (!text) return null;
  const scale = interpolate(progress, [0, 1], [0.78, 1]);
  const rise = interpolate(progress, [0, 1], [34, 0]);
  const opacity = Math.min(1, progress);
  const shared: React.CSSProperties = {
    fontFamily: 'Arial, sans-serif',
    fontWeight: 800,
    opacity,
    scale,
    translate: `0px ${rise}px`,
  };
  const theme: CtaTheme = style.cta;
  const wrap: React.CSSProperties =
    theme === 'banner_full'
      ? {position: 'absolute', left: 0, right: 0, bottom: 0, padding: '34px 40px', display: 'flex', justifyContent: 'center'}
      : {
          position: 'absolute',
          left: 0,
          right: 0,
          bottom: theme === 'chip_circle' ? 150 : 120,
          display: 'flex',
          justifyContent: 'center',
        };

  const label = (
    <span
      style={{
        ...shared,
        display: 'inline-block',
        textTransform: 'uppercase',
        letterSpacing: '0.035em',
        lineHeight: 1.1,
      }}
    >
      {text}
    </span>
  );

  return (
    <AbsoluteFill style={{pointerEvents: 'none'}}>
      <div style={wrap}>
        {theme === 'solid_pill' ? (
          <div
            style={{
              backgroundColor: style.accent,
              color: '#FFFFFF',
              padding: '20px 44px',
              borderRadius: 8,
              fontSize: 42,
              boxShadow: `0 4px 20px ${style.accent}66`,
            }}
          >
            {label}
          </div>
        ) : null}

        {theme === 'card_solid' ? (
          <div
            style={{
              backgroundColor: '#FFFFFF',
              color: style.accent,
              padding: '22px 40px',
              borderRadius: 16,
              borderLeft: `8px solid ${style.accent}`,
              fontSize: 38,
              boxShadow: '0 20px 60px rgba(15,23,42,0.28)',
            }}
          >
            {label}
          </div>
        ) : null}

        {theme === 'outline' ? (
          <div
            style={{
              border: `3px solid ${style.accent}`,
              color: style.accent,
              backgroundColor: 'rgba(255,255,255,0.72)',
              padding: '18px 42px',
              borderRadius: 999,
              fontSize: 40,
            }}
          >
            {label}
          </div>
        ) : null}

        {theme === 'chip_circle' ? (
          <div
            style={{
              width: 190,
              height: 190,
              borderRadius: '50%',
              backgroundColor: style.accent,
              color: '#FFFFFF',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              textAlign: 'center',
              padding: 22,
              fontSize: 34,
              boxShadow: `0 18px 46px ${style.accent}59`,
            }}
          >
            {label}
          </div>
        ) : null}

        {theme === 'banner_full' ? (
          <div
            style={{
              width: '100%',
              backgroundImage: `linear-gradient(90deg, ${style.accent}, ${style.accent_2})`,
              color: '#FFFFFF',
              padding: '30px 40px',
              fontSize: 40,
              textAlign: 'center',
              letterSpacing: '0.14em',
            }}
          >
            {text.toUpperCase()}
          </div>
        ) : null}
      </div>
    </AbsoluteFill>
  );
};

export const StyleChrome: React.FC<Props> = ({style, ctaText, ctaVisible, ctaProgress, badgeText, highlights}) => {
  const mat = MAT[style.overlay];
  const cards = (highlights ?? []).slice(0, 3);

  return (
    <AbsoluteFill style={{pointerEvents: 'none'}}>
      {has(style, 'grid') ? <GridPattern /> : null}
      {has(style, 'gradient_orbs') ? <GradientOrbs style={style} /> : null}
      {has(style, 'accent_bar') ? <AccentBar style={style} /> : null}
      {has(style, 'quote_mark') ? <QuoteMark style={style} /> : null}
      {has(style, 'badge') ? <Badge style={style} text={badgeText ?? ''} /> : null}
      {has(style, 'stat_chip') ? <StatChip style={style} text={badgeText ?? ''} /> : null}
      {has(style, 'underline_sweep') ? <UnderlineSweep style={style} /> : null}
      {has(style, 'stat_cards') ? <StatCards style={style} items={cards} /> : null}

      <AbsoluteFill style={{backgroundImage: OVERLAYS[style.overlay] ?? OVERLAYS.vignette}} />

      {mat ? (
        <AbsoluteFill
          style={{
            boxShadow: `inset 0 0 0 ${mat.size}px ${mat.colour}, inset 0 0 60px ${mat.colour}66`,
          }}
        />
      ) : null}

      {ctaVisible ? <Cta style={style} text={ctaText} progress={ctaProgress} /> : null}
    </AbsoluteFill>
  );
};
