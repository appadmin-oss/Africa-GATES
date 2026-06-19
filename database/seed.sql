-- AFRICA GATES — SEED DATA (Run AFTER schema.sql)
--
-- Re-runnable seed. Uses DELETE (not TRUNCATE) so the FK from
-- gates_judge_criteria_scores → gates_award_categories doesn't reject it
-- when the seed is re-applied from phpMyAdmin a statement at a time.
-- FK checks are disabled around the cleanup so child rows in transient
-- tables don't block the parent wipe.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
DELETE FROM gates_judge_criteria_scores;
DELETE FROM gates_judge_scores;
DELETE FROM gates_votes;
DELETE FROM gates_nominees;
DELETE FROM gates_nominations;
DELETE FROM gates_award_categories;
DELETE FROM gates_award_cycles;
DELETE FROM gates_award_programmes;
SET FOREIGN_KEY_CHECKS=1;

INSERT INTO gates_award_programmes(id,slug,title,subtitle,description,scope,icon_emoji,sort_order,is_active) VALUES
(1,'principals','Incredible Principal Awards','Honouring Educational Leadership Across Africa','Recognising school principals who have demonstrated exceptional commitment to academic excellence, leadership, and community transformation.','continental','🎓',1,1),
(2,'incorruptible','Incorruptible Awards','Raising a Generation Defined by Integrity','Flagship recognition dedicated to celebrating young individuals defined by integrity, discipline, and values-driven living.','continental','🛡️',2,1),
(3,'carol','Carol Awards','Celebrating Excellence in Choral Music','Dedicated to celebrating excellence in choral music and spiritual expression, promoting unity, faith, and cultural identity across Africa.','continental','🎶',3,1),
(4,'business','African Business Excellence Awards','Recognising Enterprise & Entrepreneurship','Recognising outstanding businesses and entrepreneurs contributing to economic growth, innovation, and community development across Africa.','continental','🏢',4,1);

-- Cycles are keyed to the CURRENT year. The app matches the active cycle with
-- WHERE year = date('Y'), so hardcoding a past year leaves every programme dark
-- once the year rolls over. YEAR(CURDATE()) keeps a fresh seed always current.
INSERT INTO gates_award_cycles(id,programme_id,year,edition_label,status,nominations_open,nominations_close,voting_open,voting_close,results_date) VALUES
(1,1,YEAR(CURDATE()),CONCAT(YEAR(CURDATE()),' Edition'),'nominations',
   CONCAT(YEAR(CURDATE()),'-01-01 00:00:00'),CONCAT(YEAR(CURDATE()),'-08-31 23:59:59'),
   CONCAT(YEAR(CURDATE()),'-09-10 00:00:00'),CONCAT(YEAR(CURDATE()),'-09-30 23:59:59'),CONCAT(YEAR(CURDATE()),'-10-15 18:00:00')),
(2,2,YEAR(CURDATE()),CONCAT(YEAR(CURDATE()),' Edition'),'nominations',
   CONCAT(YEAR(CURDATE()),'-01-01 00:00:00'),CONCAT(YEAR(CURDATE()),'-09-15 23:59:59'),
   CONCAT(YEAR(CURDATE()),'-09-20 00:00:00'),CONCAT(YEAR(CURDATE()),'-10-10 23:59:59'),CONCAT(YEAR(CURDATE()),'-10-25 18:00:00')),
(3,3,YEAR(CURDATE()),CONCAT(YEAR(CURDATE()),' Edition'),'voting',
   CONCAT(YEAR(CURDATE()),'-01-01 00:00:00'),CONCAT(YEAR(CURDATE()),'-07-31 23:59:59'),
   CONCAT(YEAR(CURDATE()),'-08-01 00:00:00'),CONCAT(YEAR(CURDATE()),'-12-20 23:59:59'),CONCAT(YEAR(CURDATE()),'-12-28 18:00:00')),
(4,4,YEAR(CURDATE()),CONCAT(YEAR(CURDATE()),' Edition'),'voting',
   CONCAT(YEAR(CURDATE()),'-01-01 00:00:00'),CONCAT(YEAR(CURDATE()),'-07-31 23:59:59'),
   CONCAT(YEAR(CURDATE()),'-08-01 00:00:00'),CONCAT(YEAR(CURDATE()),'-12-15 23:59:59'),CONCAT(YEAR(CURDATE()),'-12-22 18:00:00'));

-- Principals (cycle 1)
INSERT INTO gates_award_categories(cycle_id,slug,title,description,sort_order) VALUES
(1,'academic-excellence','Academic Excellence Award','Recognises principals who have significantly improved academic standards, student performance, and overall school outcomes.',1),
(1,'community-engagement','Community Engagement Award','Honours principals who actively collaborate with parents, local leaders, and residents to drive community development and inclusive education.',2),
(1,'innovation-education','Innovation in Education Award','Celebrates forward-thinking principals who introduce creative teaching methods, digital learning systems, and progressive educational practices.',3),
(1,'leadership-mentorship','Leadership & Mentorship Award','Acknowledges principals who demonstrate exceptional leadership and actively mentor students, teachers, and future leaders.',4),
(1,'social-development','Social Development & Impact Award','Highlights principals whose influence extends beyond the classroom into shaping the moral, social, and developmental growth of the community.',5);

-- Incorruptible (cycle 2) — updated to stakeholder-specified categories
INSERT INTO gates_award_categories(cycle_id,slug,title,description,sort_order) VALUES
(2,'teachers-choice','Teachers\' Choice Award','Selected by educators based on character, honesty, discipline, and outstanding leadership qualities in and out of the classroom.',1),
(2,'creative-change-maker','Creative Change-Maker Award','For using arts, music, writing, innovation, or creativity to promote positive values and measurable social impact in the community.',2),
(2,'volunteer-service','Volunteer Service Award','For outstanding participation in community service, charity, and humanitarian activities that uplift others selflessly.',3),
(2,'young-peacemaker','Young Peacemaker Award','For promoting peace, unity, conflict resolution, and harmony among peers and within the broader community.',4),
(2,'parents-pride','Parent\'s Pride Award','For children whose conduct and achievements reflect exceptional upbringing, strong family values, and outstanding character.',5),
(2,'most-improved-character','Most Improved Character Award','For remarkable growth and transformation in behavior, discipline, and personal values — honoring the journey of positive change.',6),
(2,'incorruptible-child-of-year','Incorruptible Child of the Year','Grand Prize — awarded to the child who demonstrates exceptional honesty, integrity, courage, responsibility, and positive influence among peers.',7);

-- Carol (Choral) Awards (cycle 3)
INSERT INTO gates_award_categories(cycle_id,slug,title,description,sort_order) VALUES
(3,'most-influential-choir','Most Influential Choir','Awarded to the choir whose music, ministry, and presence has had the greatest impact on audiences and communities.',1),
(3,'best-contemporary-choir','Best Contemporary Choir','Celebrating a choir that excels in modern choral styles, blending tradition with fresh, current expression.',2),
(3,'most-artistic-choir','Most Artistic Choir','Recognising outstanding creativity, originality, and artistic presentation in choral performance.',3),
(3,'most-creative-choir','Most Creative Choir','Honouring the choir that brings the most inventive arrangements, concepts, and stage presence to their performances.',4),
(3,'best-dressed-choir','Best Dressed Choir','Celebrating the choir whose attire, coordination, and visual presentation best complements their performance.',5);

-- Business Excellence Awards (cycle 4) — Alimosho criteria: min 5 staff, physical office, demonstrated excellence
INSERT INTO gates_award_categories(cycle_id,slug,title,description,sort_order) VALUES
(4,'entrepreneur-of-year','Entrepreneur of the Year','Recognizing an individual who has shown exceptional vision, leadership, and innovation in their business.',1),
(4,'small-business-champion','Small Business Champion','Highlighting a small business that has made a significant impact in its industry and community.',2),
(4,'innovative-startup','Innovative Start-up','Celebrating a start-up that has shown creativity, adaptability, and a pioneering spirit.',3),
(4,'csr-advocate','Corporate Social Responsibility (CSR) Advocate','Honoring a business that has demonstrated a strong commitment to social responsibility and community development.',4),
(4,'tech-pioneer','Tech Pioneer','Recognizing an individual or business at the forefront of technological innovation in the community.',5),
(4,'sustainable-business','Sustainable Business Leader','Celebrating a business in Alimosho that prioritizes environmental sustainability and ethical practices.',6),
(4,'customer-service','Customer Service Excellence','Highlighting a business that consistently goes above and beyond in serving its customers.',7);

-- Admin-configurable settings: donation-to-votes multiplier
-- INSERT IGNORE: safe to re-run; skips rows that already exist
INSERT IGNORE INTO gates_admin_settings(setting_key,setting_value,description) VALUES
('donation_votes_per_1000','5','Bonus votes awarded per ₦1,000 donated (admin configurable)'),
('donation_vote_enabled','1','Enable donation-based bonus votes — 1 = yes, 0 = no'),
('donation_vote_min_amount','1000','Minimum donation amount (₦) to qualify for bonus votes');

SET FOREIGN_KEY_CHECKS=1;
