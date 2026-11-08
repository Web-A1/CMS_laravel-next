import HorizontalScrollRegion from "@/components/accessibility/HorizontalScrollRegion";
import SectionIntro from "@/components/content/SectionIntro";
import TextLink from "@/components/content/TextLink";
import { ButtonLink } from "@/components/button/Button";
import ReviewPreviewCard from "@/components/reviews/ReviewCard";
import type { PublicReview } from "@/lib/reviewsApi";
import HomeReviewsLazyInteractivity from "./HomeReviewsLazyInteractivity";

type HomeReviewsProps = {
  readonly reviews: ReadonlyArray<PublicReview>;
};

const homeReviewsPreviewId = "home-reviews-static-preview";

export default function HomeReviews({ reviews }: HomeReviewsProps) {
  const hasMarquee = reviews.length >= 5;

  return (
    <section className="home-section home-reviews" id="reviews" data-ui="home.reviews" data-desktop-grid={hasMarquee ? "true" : undefined}>
      <SectionIntro eyebrow="Отзывы студентов" className="home-reviews__heading" dataUi="home.reviews">
        не только <span>любят,</span> но и <span>советуют</span> другим студентам
      </SectionIntro>
      <p className="home-reviews__description ui-card-text" data-ui="home.reviews.description">
        Здесь собраны последние отзывы, которые мы получили от студентов наших курсов.
      </p>

      <div className="home-reviews__feed">
        <div className="home-reviews__viewport">
          {reviews.length === 0 ? (
            <p className="home-reviews__empty ui-card-text" data-ui="home.reviews.empty">
              Отзывы появятся после публикации первых проверенных материалов.
            </p>
          ) : (
            <HorizontalScrollRegion
              id={homeReviewsPreviewId}
              className="home-reviews-track home-reviews-static-preview"
              role="region"
              tabIndex={0}
              aria-label="Отзывы студентов"
              data-ui="home.reviews.staticList"
            >
              {reviews.map((review) => (
                <ReviewPreviewCard key={review.id} review={review} socialDesktop={hasMarquee} dataUi="home.reviews.staticCard" />
              ))}
            </HorizontalScrollRegion>
          )}

          <HomeReviewsLazyInteractivity
            reviews={reviews}
            previewId={homeReviewsPreviewId}
            hasMarquee={hasMarquee}
          />
        </div>
      </div>

      <TextLink href="/reviews" className="home-reviews__mobile-link" data-ui="home.reviews.moreLink">
        Больше отзывов
      </TextLink>
      <ButtonLink href="/reviews" className="home-reviews__more-button" fullWidth={false} data-ui="home.reviews.moreButton">
        Больше отзывов
      </ButtonLink>
    </section>
  );
}
