import React from 'react';
import {Composition} from 'remotion';
import {ProductAd, defaultProductAdProps} from './compositions/ProductAd';
import type {ProductAdProps} from './types';

const FPS = 30;
const WIDTH = 1080;
const HEIGHT = 1920;

export const RemotionRoot: React.FC = () => {
  return (
    <>
      <Composition
        id="ProductAd"
        component={ProductAd}
        durationInFrames={FPS * 5}
        fps={FPS}
        width={WIDTH}
        height={HEIGHT}
        defaultProps={defaultProductAdProps}
        calculateMetadata={async ({props}) => {
          const p = props as ProductAdProps;
          const seconds = Math.max(1, Number(p.durationInSeconds) || 5);
          return {
            durationInFrames: Math.round(seconds * FPS),
            props,
          };
        }}
      />
    </>
  );
};
