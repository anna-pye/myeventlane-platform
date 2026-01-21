/**
 * @file
 * MEL Card Carousel (Swiper)
 * Initialises only when .mel-card-carousel exists. Safe to load globally.
 */

import Swiper from 'swiper';
import { Navigation, Keyboard } from 'swiper/modules';
import 'swiper/css';
import 'swiper/css/navigation';

Swiper.use([Navigation, Keyboard]);

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.mel-card-carousel').forEach((carousel) => {
    new Swiper(carousel, {
      slidesPerView: 1.1,
      spaceBetween: 16,
      watchOverflow: true,
      keyboard: {
        enabled: true,
      },
      navigation: {
        nextEl: carousel.querySelector('.swiper-button-next'),
        prevEl: carousel.querySelector('.swiper-button-prev'),
      },
      breakpoints: {
        768: {
          slidesPerView: 2,
          spaceBetween: 24,
        },
      },
    });
  });
});
