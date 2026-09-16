import './HeroHeader.css';

export default function HeroHeader() {
  return (
    <header className="hero-header" role="banner">
      <h1 className="visually-hidden">MainzWare Portal</h1>
      <picture className="hero-header__picture">
        <source
          media="(max-width: 768px)"
          srcSet="/img/mainzware-mobile-header.webp"
          width="2172"
          height="724"
        />
        <img
          className="hero-header__img"
          src="/img/mainzware-desktop-header.webp"
          alt=""
          width="2172"
          height="724"
          fetchPriority="high"
          decoding="async"
        />
      </picture>
    </header>
  );
}
