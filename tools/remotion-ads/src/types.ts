import type {ProductStyle} from './styles';

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
  transition?:
    | 'jump_cut'
    | 'fade'
    | 'zoom_in'
    | 'slide_left'
    | 'slide_right'
    | 'wipe'
    | 'random';
  text_on_screen?: string;
  videoStartFrame?: number;
};

export type ProductAdProps = {
  preset: string;
  styleId?: string;
  style?: Partial<ProductStyle>;
  voiceSrc: string;
  musicSrc?: string | null;
  musicVolume?: number;
  musicAttribution?: string | null;
  words: WordTiming[];
  clips: ClipPlan[];
  durationInSeconds: number;
  ctaText?: string;
};
