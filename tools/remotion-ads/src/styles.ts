import catalog from '../styles.json';

export type KenBurns = 'in' | 'out' | 'alternate';
export type Overlay =
  | 'vignette'
  | 'brand_gradient'
  | 'hero_dark'
  | 'light_card'
  | 'dark_orbs'
  | 'light_cards'
  | 'scrim_bottom';
export type Decoration =
  | 'spark'
  | 'gradient_orbs'
  | 'grid'
  | 'accent_bar'
  | 'quote_mark'
  | 'badge'
  | 'stat_chip'
  | 'stat_cards'
  | 'underline_sweep';
export type CaptionTheme = 'bold_karaoke' | 'pill_accent' | 'light_card' | 'minimal_line' | 'mono_stat';
export type CtaTheme = 'solid_pill' | 'card_solid' | 'outline' | 'chip_circle' | 'banner_full';
export type MediaTransition = 'fade' | 'slide_left' | 'slide_right' | 'wipe' | 'zoom_in' | 'random' | 'jump_cut';

export type ProductStyle = {
  id: string;
  label: string;
  description: string;
  based_on: string;
  preset_base: 'product_presenter' | 'quick_transition';
  background: string;
  overlay: Overlay;
  accent: string;
  accent_2: string;
  text: string;
  muted: string;
  decorations: Decoration[];
  captions: CaptionTheme;
  cta: CtaTheme;
  transitions: MediaTransition[];
  ken_burns: KenBurns;
  clip_seconds: number;
  video_ratio: [number, number];
  energy: number;
};

type CatalogFile = {
  defaults: Record<string, unknown>;
  styles: Array<Record<string, unknown>>;
};

const file = catalog as unknown as CatalogFile;

const FALLBACK_ID = 'social_ad';

function normalize(raw: Record<string, unknown>): ProductStyle {
  const d = file.defaults ?? {};
  const ratio = (raw.video_ratio ?? d.video_ratio) as [number, number];
  return {
    id: String(raw.id ?? FALLBACK_ID),
    label: String(raw.label ?? raw.id ?? FALLBACK_ID),
    description: String(raw.description ?? ''),
    based_on: String(raw.based_on ?? ''),
    preset_base: (raw.preset_base ?? d.preset_base) as ProductStyle['preset_base'],
    background: String(raw.background ?? d.background),
    overlay: (raw.overlay ?? d.overlay) as Overlay,
    accent: String(raw.accent ?? d.accent),
    accent_2: String(raw.accent_2 ?? d.accent),
    text: String(raw.text ?? d.text),
    muted: String(raw.muted ?? d.muted),
    decorations: (raw.decorations ?? d.decorations) as Decoration[],
    captions: (raw.captions ?? d.captions) as CaptionTheme,
    cta: (raw.cta ?? d.cta) as CtaTheme,
    transitions: (raw.transitions ?? d.transitions) as MediaTransition[],
    ken_burns: (raw.ken_burns ?? d.ken_burns) as KenBurns,
    clip_seconds: Number(raw.clip_seconds ?? d.clip_seconds),
    video_ratio: [Number(ratio[0]), Number(ratio[1])],
    energy: Number(raw.energy ?? d.energy),
  };
}

export const productStyles: ProductStyle[] = (file.styles ?? []).map(normalize);

export const productStyleIds: string[] = productStyles.map((style) => style.id);

export const resolveProductStyle = (id?: string | null): ProductStyle =>
  productStyles.find((style) => style.id === id) ?? productStyles[0] ?? normalize({id: FALLBACK_ID});
