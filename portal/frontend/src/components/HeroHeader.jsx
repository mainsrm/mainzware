import './HeroHeader.css';

export default function HeroHeader() {
  return (
    <header className="hero-header" role="banner">
      <h1 className="visually-hidden">Mainz World</h1>
      <picture className="hero-header__picture">
        <source
          media="(max-width: 768px)"
          srcSet="/img/MainzWorld_Mobile_Header.webp"
          width="2171"
          height="724"
        />
        <img
          className="hero-header__img"
          src="/img/MainzWorld_Desktop_Header.webp"
          alt=""
          width="1983"
          height="469"
          fetchPriority="high"
          decoding="async"
        />
      </picture>
    </header>
  );
}
