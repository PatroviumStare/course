USE newsroom;

INSERT IGNORE INTO categories (name, slug, description) VALUES
('Наука', 'science', 'Открытия, исследования и технологии'),
('Природа', 'nature', 'Окружающая среда и сохранение биоразнообразия'),
('Политика', 'politics', 'Общественные решения и международные договоренности'),
('Знаменитости и кино', 'culture', 'Кино, культура и люди'),
('Спорт', 'sport', 'Соревнования и достижения'),
('Киберспорт', 'esports', 'Соревновательные игры и индустрия'),
('Омская область', 'omsk', 'Важные события и позитивные перемены региона');

INSERT IGNORE INTO users (id, name, email, password_hash, role) VALUES
(1, 'Редакция Дайджеста', 'editor@example.local', '$2y$10$ZtpWOdbmC0U9VwgVnEmF7OEviUeDy9r0MwJIqSncPiPv6OdvTYZAu', 'admin');

INSERT IGNORE INTO articles (id, author_id, category_id, title, subtitle, slug, body, source_url, status, views, published_at)
SELECT s.id, 1, c.id, s.title, s.subtitle, s.slug, s.body, s.source_url, 'published', s.views, s.published_at
FROM (
    SELECT 1 id, 'Европейская обсерватория Euclid представила первые научные результаты' title, 'Космический телескоп показал новые данные о галактиках и структуре Вселенной.' subtitle, 'euclid-first-data' slug, '<p>Миссия Euclid опубликовала ранний набор научных результатов. Наблюдения помогают исследователям изучать распределение галактик и уточнять методы картирования крупномасштабной структуры Вселенной.</p><p>Короткие публикации и изображения миссии доступны в материалах Европейского космического агентства. Набор данных стал первым шагом к созданию большой трехмерной карты неба.</p>' body, 'https://www.esa.int/Science_Exploration/Space_Science/Euclid' source_url, 0 views, '2025-03-19 10:00:00' published_at
    UNION ALL SELECT 2, 'Миссия Europa Clipper отправилась к спутнику Юпитера', 'Аппарат NASA будет исследовать ледяную поверхность и океанические условия Европы.', 'europa-clipper-launch', '<p>NASA запустило миссию Europa Clipper 14 октября 2024 года. Космический аппарат направился к Европе — спутнику Юпитера, под ледяной оболочкой которого предполагается наличие океана.</p><p>Во время серии близких пролетов инструменты будут изучать поверхность, состав и толщину ледяной коры, чтобы оценить условия, важные для поиска потенциально обитаемых сред.</p>', 'https://science.nasa.gov/mission/europa-clipper/', 0, '2024-10-14 10:00:00'
    UNION ALL SELECT 3, 'Европейский закон закрепил план восстановления природы', 'Страны ЕС получили общие обязательства по восстановлению экосистем и местообитаний.', 'eu-nature-restoration-law', '<p>Регламент Европейского союза о восстановлении природы вступил в силу в августе 2024 года. Документ устанавливает общие цели по восстановлению деградировавших экосистем на суше и в морской среде.</p><p>Национальные планы должны определить меры и сроки, а прогресс предполагается оценивать по состоянию местообитаний и видов.</p>', 'https://environment.ec.europa.eu/topics/nature-and-biodiversity/nature-restoration-law_en', 0, '2024-08-18 10:00:00'
    UNION ALL SELECT 4, 'Всемирная программа восстановления лесов расширила поддержку проектов', 'Партнерские инициативы помогают возвращать деревья в деградировавшие ландшафты.', 'forest-restoration-projects', '<p>Международные программы восстановления ландшафтов объединяют местные сообщества, исследователей и природоохранные организации. Проекты включают восстановление лесов, защиту водосборов и наблюдение за биоразнообразием.</p><p>Подробности о действующих инициативах и методах мониторинга публикует Продовольственная и сельскохозяйственная организация ООН.</p>', 'https://www.fao.org/forestry/our-focus/forest-landscape-restoration/en/', 0, '2025-04-22 10:00:00'
    UNION ALL SELECT 5, 'Страны ЕС приняли общеевропейские правила восстановления экосистем', 'Новый регламент описывает измеримые экологические цели и национальные планы.', 'eu-ecosystem-restoration', '<p>Совет Европейского союза одобрил закон о восстановлении природы в июне 2024 года. Он стал первым комплексным общеевропейским документом, направленным на восстановление экосистем.</p><p>Странам предстоит планировать меры с учетом особенностей территорий, научных данных и консультаций с заинтересованными сторонами.</p>', 'https://www.consilium.europa.eu/en/policies/nature-restoration/', 0, '2024-06-17 10:00:00'
    UNION ALL SELECT 6, '«Аноре» получила пять премий на церемонии «Оскар»', 'Фильм Шона Бейкера стал главным победителем 97-й церемонии Американской киноакадемии.', 'anora-five-oscars', '<p>На церемонии Academy Awards 2025 картина «Аноре» получила награды за лучший фильм, режиссуру, оригинальный сценарий и монтаж. Майки Мэдисон была отмечена премией за женскую роль.</p><p>Результаты церемонии опубликованы Американской киноакадемией; полный список лауреатов доступен на официальном сайте.</p>', 'https://www.oscars.org/oscars/ceremonies/2025', 0, '2025-03-03 10:00:00'
    UNION ALL SELECT 7, '«Аноре» стала лидером премии BAFTA по числу наград', 'Картина получила семь британских кинопремий, включая награду за лучший фильм.', 'anora-bafta-awards', '<p>Фильм «Аноре» получил семь наград BAFTA в 2025 году. В числе отмеченных категорий — лучший фильм, режиссура, оригинальный сценарий и монтаж.</p><p>Награды Британской академии объединяют участников киноиндустрии и отмечают работы, вышедшие в прокат в течение года.</p>', 'https://www.bafta.org/awards/film', 0, '2025-02-17 10:00:00'
    UNION ALL SELECT 8, 'Янник Синнер выиграл Australian Open второй год подряд', 'Итальянский теннисист защитил титул на турнире Большого шлема в Мельбурне.', 'sinner-australian-open-2025', '<p>Янник Синнер победил Александра Зверева в финале Australian Open 2025 в трех сетах. Для итальянского теннисиста это стала вторая подряд победа на турнире.</p><p>Официальный сайт соревнования опубликовал итоговый счет и статистику матча.</p>', 'https://ausopen.com/articles/news/jannik-sinner-wins-australian-open-2025', 0, '2025-01-26 10:00:00'
    UNION ALL SELECT 9, 'Париж провел летнюю Олимпиаду 2024 года с новым городским форматом', 'Соревнования использовали знаковые пространства города и временные спортивные площадки.', 'paris-2024-urban-venues', '<p>Олимпийские соревнования в Париже проходили на временных площадках и известных городских пространствах, включая центр города. Организаторы также представили новые форматы доступа болельщиков к соревнованиям.</p><p>Итоговые отчеты Международного олимпийского комитета описывают организацию турниров и устойчивость спортивных объектов.</p>', 'https://olympics.com/en/olympic-games/paris-2024', 0, '2024-08-11 10:00:00'
    UNION ALL SELECT 10, 'ENCE объявила о банкротстве и завершении деятельности', 'Финская киберспортивная организация сообщила о намерении подать заявление о банкротстве после 13 лет работы.', 'ence-bankruptcy', '<p>В октябре 2026 года финская киберспортивная организация ENCE объявила о намерении подать заявление о банкротстве и завершить деятельность после тринадцати лет работы. Новость вновь обратила внимание на экономическую устойчивость клубов и долгосрочную поддержку игроков и сотрудников.</p><p>Сообщение следует читать как новость о компании, а не как итог спортивного сезона. В первоисточнике приводятся обстоятельства решения и дальнейшие шаги организации.</p>', 'https://e.sport.fr/news/ence-en-faillite-une-page-de-lhistoire-de-cs-se-tourne-776074.shtm', 0, '2026-10-26 16:00:00'
    UNION ALL SELECT 11, 'Киберспортивные клубы обновляют подходы к поддержке молодых игроков', 'Команды расширяют академические составы и программы подготовки специалистов.', 'esports-academy-programs', '<p>Крупные организации в киберспорте поддерживают молодежные составы и программы развития игроков. Такие проекты помогают знакомить новичков с командной подготовкой, тренировочной дисциплиной и профессиональной инфраструктурой.</p><p>Данные о составах и программах публикуются в официальных каналах конкретных клубов.</p>', 'https://www.hltv.org/', 0, '2025-05-16 10:00:00'
    UNION ALL SELECT 12, 'Омская область развивает туристические маршруты и городские пространства', 'Региональные программы связывают природные территории с музеями и объектами культуры.', 'omsk-tourism-routes', '<p>Омская область развивает внутренний туризм, объединяя городские культурные площадки и природные маршруты региона. Для путешественников публикуются карты, описания объектов и сезонные рекомендации.</p><p>Актуальные маршруты и условия посещения следует уточнять на официальных региональных ресурсах перед поездкой.</p>', 'https://omskportal.ru/', 0, '2025-06-12 10:00:00'
    UNION ALL SELECT 13, 'Омские учреждения культуры представили летнюю программу мероприятий', 'Музеи и культурные площадки подготовили открытые события для жителей и гостей города.', 'omsk-summer-culture', '<p>Культурные учреждения Омска формируют летние программы с выставками, экскурсиями и городскими мероприятиями. Расписания публикуются площадками и могут обновляться в течение сезона.</p><p>Информация о времени и билетах сверяется по официальным афишам учреждений.</p>', 'https://culture.omsk.ru/', 0, '2025-06-02 10:00:00'
    UNION ALL SELECT 14, 'Болгария и Румыния полностью присоединились к Шенгенской зоне', 'С 1 января 2025 года отменен контроль на внутренних сухопутных границах обеих стран.', 'bulgaria-romania-schengen', '<p>С 1 января 2025 года Болгария и Румыния стали полноправными участниками Шенгенской зоны после отмены проверок на внутренних сухопутных границах. Ранее в 2024 году был снят контроль на внутренних воздушных и морских границах.</p><p>Решение Совета Европейского союза завершило процесс присоединения и изменило порядок поездок внутри общей зоны свободного передвижения.</p>', 'https://www.consilium.europa.eu/en/press/press-releases/2024/12/12/council-decides-to-lift-land-border-controls-with-bulgaria-and-romania/', 0, '2025-01-01 10:00:00'
) s JOIN categories c ON c.slug = CASE WHEN s.id IN (1,2) THEN 'science' WHEN s.id IN (3,4) THEN 'nature' WHEN s.id IN (5,14) THEN 'politics' WHEN s.id IN (6,7) THEN 'culture' WHEN s.id IN (8,9) THEN 'sport' WHEN s.id IN (10,11) THEN 'esports' ELSE 'omsk' END;

INSERT IGNORE INTO tags (name, slug) VALUES
('Космос','space'),('Исследования','research'),('Экология','ecology'),('Кино','film'),('Теннис','tennis'),('Олимпиада','olympics'),('Киберспорт','esports'),('Омск','omsk'),('Европа','europe');

INSERT IGNORE INTO article_tags (article_id, tag_id)
SELECT a.id, t.id FROM articles a JOIN tags t ON
    (a.slug IN ('euclid-first-data','europa-clipper-launch') AND t.slug IN ('space','research')) OR
    (a.slug IN ('eu-nature-restoration-law','forest-restoration-projects','eu-ecosystem-restoration') AND t.slug IN ('ecology','europe')) OR
    (a.slug IN ('anora-five-oscars','anora-bafta-awards') AND t.slug = 'film') OR
    (a.slug = 'sinner-australian-open-2025' AND t.slug = 'tennis') OR
    (a.slug = 'paris-2024-urban-venues' AND t.slug = 'olympics') OR
    (a.slug IN ('ence-bankruptcy','esports-academy-programs') AND t.slug = 'esports') OR
    (a.slug IN ('omsk-tourism-routes','omsk-summer-culture') AND t.slug = 'omsk');

UPDATE articles SET image_url = CASE slug
    WHEN 'euclid-first-data' THEN '/assets/images/stories/science.png'
    WHEN 'europa-clipper-launch' THEN '/assets/images/stories/science.png'
    WHEN 'eu-nature-restoration-law' THEN '/assets/images/stories/nature.png'
    WHEN 'forest-restoration-projects' THEN '/assets/images/stories/nature.png'
    WHEN 'eu-ecosystem-restoration' THEN '/assets/images/stories/nature.png'
    WHEN 'anora-five-oscars' THEN '/assets/images/stories/culture.png'
    WHEN 'anora-bafta-awards' THEN '/assets/images/stories/culture.png'
    WHEN 'sinner-australian-open-2025' THEN '/assets/images/stories/sport.png'
    WHEN 'paris-2024-urban-venues' THEN '/assets/images/stories/sport.png'
    WHEN 'ence-bankruptcy' THEN '/assets/images/stories/esports.png'
    WHEN 'esports-academy-programs' THEN '/assets/images/stories/esports.png'
    WHEN 'omsk-tourism-routes' THEN '/assets/images/stories/omsk.png'
    WHEN 'omsk-summer-culture' THEN '/assets/images/stories/omsk.png'
    WHEN 'bulgaria-romania-schengen' THEN '/assets/images/stories/politics.png'
END
WHERE slug IN ('euclid-first-data','europa-clipper-launch','eu-nature-restoration-law','forest-restoration-projects','eu-ecosystem-restoration','anora-five-oscars','anora-bafta-awards','sinner-australian-open-2025','paris-2024-urban-venues','ence-bankruptcy','esports-academy-programs','omsk-tourism-routes','omsk-summer-culture','bulgaria-romania-schengen');

UPDATE articles SET
    title = 'ENCE объявила о банкротстве и завершении деятельности',
    subtitle = 'Финская киберспортивная организация сообщила о намерении подать заявление о банкротстве после 13 лет работы.',
    body = '<p>В октябре 2026 года финская киберспортивная организация ENCE объявила о намерении подать заявление о банкротстве и завершить деятельность после тринадцати лет работы. Новость вновь обратила внимание на экономическую устойчивость клубов и долгосрочную поддержку игроков и сотрудников.</p><p>Сообщение следует читать как новость о компании, а не как итог спортивного сезона. В первоисточнике приводятся обстоятельства решения и дальнейшие шаги организации.</p>',
    source_url = 'https://e.sport.fr/news/ence-en-faillite-une-page-de-lhistoire-de-cs-se-tourne-776074.shtm',
    published_at = '2026-10-26 16:00:00'
WHERE slug = 'ence-bankruptcy';
