import ContactModalTrigger from "@/components/contact/ContactModalTrigger";

export default function HomeHeroContactCta() {
  return (
    <div className="home-hero__cta relative z-10 flex flex-col" data-ui="home.hero.cta">
      <p className="home-hero__cta-copy home-hero__cta-copy--mobile">
        Напиши нам, чтобы{" "}
        <br />
        получить <span>бесплатный урок</span>
      </p>
      <ContactModalTrigger className="home-hero__button home-hero__button--mobile" data-ui="home.hero.writeButton">
        Написать
      </ContactModalTrigger>
      <ContactModalTrigger
        className="home-hero__button home-hero__button--desktop"
        data-ui="home.hero.writeButtonDesktop"
        fullWidth={false}
      >
        Получить бесплатный урок
      </ContactModalTrigger>
    </div>
  );
}
