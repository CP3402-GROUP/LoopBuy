-- Older synchronous assessments were stored as pending even though the ML
-- request had already finished. Preserve those decisions without approving
-- them: owners can run a fresh assessment to obtain the new audited signal
-- count and the bounded automatic-publication decision.
UPDATE listings AS listing
JOIN (
    SELECT listing_id, MAX(assessment_id) AS assessment_id
    FROM scam_assessments
    GROUP BY listing_id
) AS latest
  ON latest.listing_id = listing.listing_id
JOIN scam_assessments AS assessment
  ON assessment.assessment_id = latest.assessment_id
SET listing.moderation_status = CASE
        WHEN assessment.model_version IN ('unavailable', 'invalid-provider-response') THEN 'unavailable'
        ELSE 'review'
    END,
    listing.updated_at = CURRENT_TIMESTAMP(6),
    listing.revision = listing.revision + 1
WHERE listing.status = 'under_review'
  AND listing.moderation_status = 'pending'
  AND assessment.status = 'completed';
