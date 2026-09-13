export type WordTiming = {
  inicio: number;
  fin: number;
  texto: string;
};

export type ClipPlan = {
  start_s: number;
  end_s: number;
  media: string;
  media_type: 'image' | 'video';
  ken_burns?: 'in' | 'out' | 'none';
  transition?: 'jump_cut' | 'fade' | 'zoom_in';
  text_on_screen?: string;
};

export type ProductAdProps = {
  preset: 'product_presenter' | 'quick_transition';
  voiceSrc: string;
  musicSrc?: string | null;
  musicVolume?: number;
  words: WordTiming[];
  clips: ClipPlan[];
  durationInSeconds: number;
  ctaText?: string;
};
