# Ads: research and decisions (28 Oct 2026)

Why the ads look and behave as they do, with the sources. Code: `src/Services/Ads/`,
`templates/partials/ad-slot.twig`, `/advertise`, `/account/ads`, `/legal/advertising`.

## What the research found, and what was done with it

| Finding | Source | What we did |
|---|---|---|
| A feed ad has five parts: who it is from, the primary text above the image, the image, then a link bar with headline, description and button. About 125 characters of text show before "See more"; headlines are kept short (27–40). | Meta ad anatomy guides ([vaizle](https://insights.vaizle.com/anatomy-of-a-facebook-ad/), [gomarble](https://www.gomarble.ai/blog/optimal-primary-text-size-facebook-ads), [influee](https://influee.co/gb/blog/facebook-ad-copy)) | The card follows that anatomy exactly. Text capped at 125, headline at 40. |
| The label should be where the eye lands first (beside the name, top left), in legible type, worded as an ad. "Promoted" is ambiguous. A label below a feed item is missed. | FTC native advertising guide, as summarised by [Dorsey](https://www.dorsey.com/newsresources/blogs/tmca/2016/01/going-native-part-2-the-ftcs-native-advertising-guide-for-businesses--the-why-when-and-how-of-effective-disclosures), [Katten](https://katten.com/FTC-Issues-Much-Anticipated-Guidance-Regarding-Native-Advertising), [Verrill](https://www.verrill-law.com/blog/ftc-addresses-nativity-advertising-just-in-time-for-xmas/) | "Sponsored" sits under the advertiser's name at the top left, in the soft ink (4.8:1), never pale grey. |
| A native ad should match the page's look but be clearly distinguishable. Blending in raises clicks and lowers trust. | [IAB Native Advertising Playbook](https://www.iab.com/wp-content/uploads/2015/06/IAB-Native-Advertising-Playbook2.pdf); Aribarg & Schwartz eye-tracking study ([iMotions](https://imotions.com/blog/publications/native-advertising-in-online-news-trade-offs-among-clicks-brand-recognition-and-website-trustworthiness/)) | A white card like every card on the ground, neutral throughout, always labelled, 500px at most (a feed post, never a billboard). |
| Every platform shows where a tap goes, and who paid. | Google Ads transparency ([Google](https://support.google.com/adspolicy/answer/13733850)) | The link bar prints the domain; the card names the advertiser. |
| Every ad has a menu: hide it, report it, why am I seeing this. Reports are read by people. | Meta help ([Messenger help centre](https://www.messenger.com/help/146952742043748)), [Meta newsroom 2014](https://about.fb.com/news/2014/09/news-feed-fyi-listening-to-peoples-feedback-to-show-better-ads/amp/) | The ⋯ menu: Hide ad, Report ad (four reasons), Why am I seeing this, More ads from this advertiser, About ads. A report alone never takes an ad down. |
| Public ad libraries: every active ad, who placed it and when it started. | [Meta Ad Library](https://buffer.com/resources/meta-ad-library/), [Google Ads Transparency Center](https://support.google.com/adspolicy/answer/13733850) | `/advertise/library`, public, no sign-in, never counts a view. |
| Builders show a live preview beside the form. | Meta Ads Manager ([Jon Loomer](https://www.jonloomer.com/?p=43079)) | The builder's preview is the real card, updated as you type. |
| Most ads are reviewed within about a day; refusals give the reason; a fixed ad can be resubmitted. | Third-party summaries of Meta's process ([savemyleads](https://savemyleads.com/blog/other/meta-ads-review-time)) | A person reviews every ad; a refusal needs a reason; fix and resend. No time is promised. |
| Less text on the image performs better (the old 20% rule is gone, the advice is not). | [Adzooma](https://adzooma.com/blog/facebook-advertising-removes-20-text-rule-on-images) | Stated as a hint in the builder. |
| Status words advertisers know: In review, Active, Completed, Not delivering. | [Revealbot](https://revealbot.com/blog/facebook-ad-status/) | In review, Scheduled, Active, Paused, Completed (with why), Not approved, Cancelled. Derived, never stored. |

## Nigerian law (not legal advice; confirm with a lawyer)

- **ARCON pre-exposure vetting.** Since the ARCON Act 2022, adverts in Nigeria, online
  included, must be approved before they are published; publishing an unapproved advert is
  an offence (s.34(3)), and the risk reaches the medium that publishes it. Reported fees in
  2023 were ₦7,500 to ₦100,000 per advert, paid by the advertiser, submitted through a
  registered agency. ([Nairametrics](https://nairametrics.com/2023/05/11/nigerians-to-pay-more-for-social-media-ads-as-arcon-releases-vetting-rates/), [Techpoint](https://techpoint.africa/insight/arcon-fight-digital-advertising-nigeria/), [Brand Times](https://www.brandtimes.com.ng/arcon-begins-enforcement-of-digital-ad-content-without-pre-exposure-approval/), [ThisDay, 22 Oct 2026](https://www.thisdaylive.com/2026/10/22/arcon-warns-parties-media-houses-against-unapproved-political-ads/))
  → The builder asks for the ARCON approval number; the reviewer sees it or its absence.
  Whether to make it required is the owner's decision.
- **Misleading marketing** is prohibited by the FCCPA 2018 (s.123). ([Chambers](https://practiceguides.chambers.com/practice-guides/advertising-and-marketing-2024/nigeria))
  → The policy says so; the advertiser confirms their claims are true.
- **An NGO selling ads.** Incorporated trustees (CAMA 2020 Part F) may earn income if it
  is applied to the organisation's objects and never distributed to members. Check the
  constitution's objects allow it. ([Council on Foundations](https://cof.org/country-notes/nonprofit-law-nigeria))
- **Tax.** Under the Nigeria Tax Act 2025 (in force 1 Jan 2026) income from a "trade or
  business" is taxable even for a charity: ad sales are not covered by the charitable
  exemption, whatever the price. ([BusinessDay](https://businessday.ng/business-economy/article/nigeria-draws-the-tax-line-between-ngo-and-business/), [Fiduciary Services](https://fiduciaryservicesltd.com/my-charity-my-tax-what-donors-and-nonprofits-need-to-know-under-the-nigeria-tax-acts-2025/))
- **VAT** is 7.5% on advertising; the registration threshold is reported variously as
  ₦25m, ₦50m or ₦100m turnover. ([TaxJeje](https://taxjeje.com/insights/vat-threshold-50-million-not-100-million))
- **Low prices** are lawful as far as this research found; nothing requires a minimum.

## Beta decisions (owner)

- No location targeting (28 Oct 2026). Ads are chosen by the page alone.
- Free trial only for now: one per account, 7 days or 1,000 views.
- The policy is a document in the legal directory (`/legal/advertising`), not a page of its
  own (28 Oct 2026); its standards and figures are generated from `AdRules`.
