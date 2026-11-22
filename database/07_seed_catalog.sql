-- 07_seed_catalog.sql: the textbook catalog (Step 18): 217 real college textbooks and standard references
-- in 22 categories, on top of the 12 books in 06_seed.sql. Books only: there are no sample people.
--
--   * Books are added with fn_create_book by the office admin, and rare titles get their limit with
--     fn_set_copy_limit. copies_available is never typed in.
--   * date_added is moved back over the last 6 months; those date moves are building the catalog, not events in it,
--     so their own audit rows are removed at the end (the "added" row shows the final date and limit).
--   * ISBNs: only ISBN-13s that database/tools/verify_isbns.php confirmed with Open Library; a title whose ISBN could
--     not be confirmed is stored without one. Local editions without a reliable ISBN have none.
-- reset.ps1 -NoSeed / NO_SEED=1 reset.sh skip this file together with 06_seed.sql.
\set ON_ERROR_STOP on
SET client_min_messages = warning;
SET search_path = lms, public;
SET TIME ZONE 'Asia/Manila';

-- ---------------------------------------------------------------------------
-- Session-only helpers (the same as in 06_seed.sql; temporary objects do not outlive a file)
-- ---------------------------------------------------------------------------
CREATE FUNCTION pg_temp.act(p_type text, p_id int) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  PERFORM set_config('app.actor_type', p_type, true), set_config('app.actor_id', coalesce(p_id::text, ''), true);
END $$;

-- A time p_days_ago days back, at a clock time, in Manila (never later than a few minutes ago).
CREATE FUNCTION pg_temp.at(p_days_ago int, p_time time) RETURNS timestamptz LANGUAGE sql STABLE AS $$
  SELECT least(((current_date - p_days_ago) + p_time)::timestamptz, now() - interval '10 minutes')
$$;

CREATE TEMP TABLE seed_cursor (last_id bigint NOT NULL);
INSERT INTO seed_cursor SELECT coalesce(max(audit_id), 0) FROM lms.audit_log;
CREATE TEMP TABLE seed_start AS SELECT last_id AS audit_from FROM seed_cursor;
CREATE FUNCTION pg_temp.stamp(p_at timestamptz) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
  UPDATE lms.audit_log SET created_at = p_at WHERE audit_id > (SELECT last_id FROM seed_cursor);
  UPDATE seed_cursor SET last_id = (SELECT coalesce(max(audit_id), 0) FROM lms.audit_log);
END $$;

-- ---------------------------------------------------------------------------
-- Books: 217 textbooks and standard references in 22 categories.
--    copies 1 to 5; rare titles (specialised, older, or a replacement cost above about ₱4,500) have 1 or 2 copies
--    and a copy_limit equal to that. date_added is spread over the last 6 months.
-- ---------------------------------------------------------------------------
CREATE TEMP TABLE seed_book (
  code text PRIMARY KEY, isbn text, title text NOT NULL, author text NOT NULL, category text NOT NULL,
  copies int NOT NULL, cost numeric(10,2) NOT NULL, lim int, added int NOT NULL, book_id int
);
INSERT INTO seed_book (code, isbn, title, author, category, copies, cost, lim, added) VALUES
  ('P1', '978-0134685991', 'Effective Java', 'Joshua Bloch', 'Programming', 3, 2850.00, NULL, 150),
  ('P2', '978-0134743356', 'Java How to Program, Early Objects', 'Paul Deitel and Harvey Deitel', 'Programming', 5, 3950.00, NULL, 170),
  ('P3', '978-1491910771', 'Head First Java', 'Kathy Sierra, Bert Bates and Trisha Gee', 'Programming', 4, 2700.00, NULL, 120),
  ('P4', '978-1718502703', 'Python Crash Course', 'Eric Matthes', 'Programming', 5, 2300.00, NULL, 90),
  ('P5', '978-1593279929', 'Automate the Boring Stuff with Python', 'Al Sweigart', 'Programming', 3, 2100.00, NULL, 60),
  ('P6', '978-0131103627', 'The C Programming Language', 'Brian W. Kernighan and Dennis M. Ritchie', 'Programming', 2, 3100.00, 2, 175),
  ('P7', '978-0393979503', 'C Programming: A Modern Approach', 'K. N. King', 'Programming', 3, 3400.00, NULL, 140),
  ('P8', '978-0321714114', 'C++ Primer', 'Stanley B. Lippman, Josée Lajoie and Barbara E. Moo', 'Programming', 2, 3300.00, NULL, 130),
  ('P9', '978-0201633610', 'Design Patterns: Elements of Reusable Object-Oriented Software', 'Erich Gamma, Richard Helm, Ralph Johnson and John Vlissides', 'Programming', 2, 3600.00, 2, 165),
  ('P10', '978-0134757599', 'Refactoring: Improving the Design of Existing Code', 'Martin Fowler', 'Programming', 2, 3200.00, NULL, 45),
  ('D1', '978-1337627900', 'Database Systems: Design, Implementation, and Management', 'Carlos Coronel and Steven Morris', 'Databases', 4, 4200.00, NULL, 160),
  ('D2', '978-0072465631', 'Database Management Systems', 'Raghu Ramakrishnan and Johannes Gehrke', 'Databases', 2, 4400.00, 2, 175),
  ('D3', '978-0131873254', 'Database Systems: The Complete Book', 'Hector Garcia-Molina, Jeffrey D. Ullman and Jennifer Widom', 'Databases', 1, 4800.00, 1, 170),
  ('D4', '978-0134773650', 'Modern Database Management', 'Jeffrey A. Hoffer, V. Ramesh and Heikki Topi', 'Databases', 3, 4100.00, NULL, 110),
  ('D5', '978-0135182796', 'SQL in 10 Minutes a Day', 'Ben Forta', 'Databases', 4, 1650.00, NULL, 80),
  ('D6', '978-1492057611', 'Learning SQL', 'Alan Beaulieu', 'Databases', 3, 2400.00, NULL, 70),
  ('D7', '978-1449373320', 'Designing Data-Intensive Applications', 'Martin Kleppmann', 'Databases', 2, 3300.00, NULL, 50),
  ('D8', '978-0321197849', 'An Introduction to Database Systems', 'C. J. Date', 'Databases', 1, 4600.00, 1, 178),
  ('D9', '978-0136788041', 'Database Design for Mere Mortals', 'Michael J. Hernandez', 'Databases', 2, 2600.00, NULL, 40),
  ('D10', '978-1491963418', 'PostgreSQL: Up and Running', 'Regina O. Obe and Leo S. Hsu', 'Databases', 2, 2200.00, NULL, 30),
  ('N1', '978-0136764052', 'Computer Networks', 'Andrew S. Tanenbaum, Nick Feamster and David J. Wetherall', 'Networking', 3, 4300.00, NULL, 150),
  ('N2', '978-0123850591', 'Computer Networks: A Systems Approach', 'Larry L. Peterson and Bruce S. Davie', 'Networking', 2, 4700.00, 2, 170),
  ('N3', '978-1337569330', 'Network+ Guide to Networks', 'Jill West, Tamara Dean and Jean Andrews', 'Networking', 4, 3900.00, NULL, 130),
  ('N4', '978-0135792735', 'CCNA 200-301 Official Cert Guide, Volume 1', 'Wendell Odom', 'Networking', 3, 3500.00, NULL, 100),
  ('N5', '978-0321336316', 'TCP/IP Illustrated, Volume 1: The Protocols', 'Kevin R. Fall and W. Richard Stevens', 'Networking', 1, 4900.00, 1, 168),
  ('N6', '978-0133506488', 'Data and Computer Communications', 'William Stallings', 'Networking', 2, 4400.00, 2, 160),
  ('N7', '978-0134444284', 'Cryptography and Network Security: Principles and Practice', 'William Stallings', 'Networking', 2, 4300.00, NULL, 90),
  ('N8', '978-0134527338', 'Network Security Essentials: Applications and Standards', 'William Stallings', 'Networking', 3, 3700.00, NULL, 75),
  ('M1', '978-0134438986', 'Thomas'' Calculus', 'Joel Hass, Christopher Heil and Maurice D. Weir', 'Mathematics', 5, 4450.00, NULL, 175),
  ('M2', '978-0321982384', 'Linear Algebra and Its Applications', 'David C. Lay, Steven R. Lay and Judi J. McDonald', 'Mathematics', 4, 4100.00, NULL, 160),
  ('M3', '978-0134462455', 'Elementary Statistics', 'Mario F. Triola', 'Mathematics', 5, 4000.00, NULL, 155),
  ('M4', '978-1305251809', 'Probability and Statistics for Engineering and the Sciences', 'Jay L. Devore', 'Mathematics', 3, 4300.00, NULL, 140),
  ('M5', '978-0470458365', 'Advanced Engineering Mathematics', 'Erwin Kreyszig', 'Mathematics', 2, 4800.00, 2, 170),
  ('M6', '978-1119443766', 'Elementary Differential Equations and Boundary Value Problems', 'William E. Boyce, Richard C. DiPrima and Douglas B. Meade', 'Mathematics', 3, 4400.00, NULL, 120),
  ('M7', '978-0321979070', 'Precalculus', 'Michael Sullivan', 'Mathematics', 5, 3800.00, NULL, 150),
  ('M8', '978-0201763904', 'A First Course in Abstract Algebra', 'John B. Fraleigh', 'Mathematics', 2, 4200.00, 2, 172),
  ('M9', '978-0471433316', 'Introduction to Real Analysis', 'Robert G. Bartle and Donald R. Sherbert', 'Mathematics', 1, 4500.00, 1, 166),
  ('L1', NULL, 'Florante at Laura', 'Francisco Balagtas', 'Literature', 5, 350.00, NULL, 175),
  ('L2', NULL, 'Dekada ''70', 'Lualhati Bautista', 'Literature', 3, 450.00, NULL, 140),
  ('L3', NULL, 'Smaller and Smaller Circles', 'F. H. Batacan', 'Literature', 3, 950.00, NULL, 100),
  ('L4', NULL, 'America Is in the Heart', 'Carlos Bulosan', 'Literature', 3, 1050.00, NULL, 120),
  ('L5', NULL, 'The Woman Who Had Two Navels and Tales of the Tropical Gothic', 'Nick Joaquín', 'Literature', 2, 1100.00, NULL, 90),
  ('L6', NULL, 'Dusk', 'F. Sionil José', 'Literature', 2, 900.00, NULL, 160),
  ('L7', '978-0385474542', 'Things Fall Apart', 'Chinua Achebe', 'Literature', 3, 750.00, NULL, 60),
  ('L8', '978-0060935467', 'To Kill a Mockingbird', 'Harper Lee', 'Literature', 3, 800.00, NULL, 45),
  ('L9', '978-0451524935', '1984', 'George Orwell', 'Literature', 3, 650.00, NULL, 30),
  ('S1', '978-0198788607', 'The Selfish Gene', 'Richard Dawkins', 'Science', 2, 1100.00, NULL, 110),
  ('S2', '978-0618249060', 'Silent Spring', 'Rachel Carson', 'Science', 2, 900.00, NULL, 130),
  ('S3', '978-0451529060', 'The Origin of Species', 'Charles Darwin', 'Science', 2, 650.00, NULL, 150),
  ('S4', '978-0062316097', 'Sapiens: A Brief History of Humankind', 'Yuval Noah Harari', 'Science', 3, 1250.00, NULL, 70),
  ('S5', '978-0226458120', 'The Structure of Scientific Revolutions', 'Thomas S. Kuhn', 'Science', 2, 1000.00, 2, 170),
  ('S6', '978-0134543536', 'Earth Science', 'Edward J. Tarbuck, Frederick K. Lutgens and Dennis Tasa', 'Science', 3, 4200.00, NULL, 140),
  ('S7', '978-0134011271', 'Environmental Science: Toward a Sustainable Future', 'Richard T. Wright and Dorothy F. Boorse', 'Science', 3, 4100.00, NULL, 125),
  ('S8', '978-1337094153', 'Living in the Environment', 'G. Tyler Miller and Scott E. Spoolman', 'Science', 2, 4000.00, NULL, 95),
  ('S9', '978-0743216302', 'The Double Helix', 'James D. Watson', 'Science', 2, 850.00, 2, 160),
  ('C1', '978-0262046305', 'Introduction to Algorithms', 'Thomas H. Cormen, Charles E. Leiserson, Ronald L. Rivest and Clifford Stein', 'Computer Science', 2, 5200.00, 2, 165),
  ('C2', '978-1119320913', 'Operating System Concepts', 'Abraham Silberschatz, Peter Baer Galvin and Greg Gagne', 'Computer Science', 4, 4300.00, NULL, 170),
  ('C3', '978-0133591620', 'Modern Operating Systems', 'Andrew S. Tanenbaum and Herbert Bos', 'Computer Science', 3, 4300.00, NULL, 150),
  ('C4', '978-0128203316', 'Computer Organization and Design RISC-V Edition', 'David A. Patterson and John L. Hennessy', 'Computer Science', 2, 4400.00, NULL, 140),
  ('C5', '978-0134092669', 'Computer Systems: A Programmer''s Perspective', 'Randal E. Bryant and David R. O''Hallaron', 'Computer Science', 2, 4500.00, 2, 130),
  ('C6', '978-0134610993', 'Artificial Intelligence: A Modern Approach', 'Stuart Russell and Peter Norvig', 'Computer Science', 2, 5500.00, 2, 120),
  ('C7', '978-1133187790', 'Introduction to the Theory of Computation', 'Michael Sipser', 'Computer Science', 2, 4400.00, NULL, 160),
  ('C8', '978-0133943030', 'Software Engineering', 'Ian Sommerville', 'Computer Science', 4, 4100.00, NULL, 155),
  ('C9', '978-0262510875', 'Structure and Interpretation of Computer Programs', 'Harold Abelson and Gerald Jay Sussman', 'Computer Science', 2, 3000.00, 2, 175),
  ('C10', '978-0321486813', 'Compilers: Principles, Techniques, and Tools', 'Alfred V. Aho, Monica S. Lam, Ravi Sethi and Jeffrey D. Ullman', 'Computer Science', 1, 4900.00, 1, 172),
  ('C11', '978-0321573513', 'Algorithms', 'Robert Sedgewick and Kevin Wayne', 'Computer Science', 3, 3900.00, NULL, 80),
  ('E1', '978-0133918922', 'Engineering Mechanics: Statics', 'R. C. Hibbeler', 'Engineering', 5, 4300.00, NULL, 178),
  ('E2', '978-0133915389', 'Engineering Mechanics: Dynamics', 'R. C. Hibbeler', 'Engineering', 4, 4300.00, NULL, 178),
  ('E3', '978-0134319650', 'Mechanics of Materials', 'R. C. Hibbeler', 'Engineering', 4, 4400.00, NULL, 170),
  ('E4', '978-0078028229', 'Fundamentals of Electric Circuits', 'Charles K. Alexander and Matthew N. O. Sadiku', 'Engineering', 4, 4350.00, NULL, 160),
  ('E5', '978-0199339136', 'Microelectronic Circuits', 'Adel S. Sedra and Kenneth C. Smith', 'Engineering', 2, 4800.00, 2, 150),
  ('E6', '978-1259822674', 'Thermodynamics: An Engineering Approach', 'Yunus A. Çengel and Michael A. Boles', 'Engineering', 3, 4450.00, NULL, 145),
  ('E7', '978-0073398273', 'Fluid Mechanics', 'Frank M. White', 'Engineering', 2, 4600.00, 2, 140),
  ('E8', '978-0134610672', 'Structural Analysis', 'R. C. Hibbeler', 'Engineering', 2, 4400.00, NULL, 120),
  ('E9', '978-1305970939', 'Principles of Geotechnical Engineering', 'Braja M. Das and Khaled Sobhan', 'Engineering', 2, 4700.00, 2, 110),
  ('E10', '978-1119405498', 'Materials Science and Engineering: An Introduction', 'William D. Callister Jr. and David G. Rethwisch', 'Engineering', 3, 4400.00, NULL, 100),
  ('E11', '978-0134549897', 'Digital Design', 'M. Morris Mano and Michael D. Ciletti', 'Engineering', 3, 4000.00, NULL, 90),
  ('A1', '978-1119411482', 'Accounting Principles', 'Jerry J. Weygandt, Paul D. Kimmel and Donald E. Kieso', 'Accounting', 5, 4300.00, NULL, 175),
  ('A2', '978-1119503682', 'Intermediate Accounting', 'Donald E. Kieso, Jerry J. Weygandt and Terry D. Warfield', 'Accounting', 3, 4500.00, NULL, 165),
  ('A3', '978-0134475585', 'Cost Accounting: A Managerial Emphasis', 'Srikant M. Datar and Madhav V. Rajan', 'Accounting', 2, 4400.00, NULL, 150),
  ('A4', '978-1259917066', 'Managerial Accounting', 'Ray H. Garrison, Eric W. Noreen and Peter C. Brewer', 'Accounting', 3, 4300.00, NULL, 140),
  ('A5', '978-0134897431', 'Auditing and Assurance Services', 'Alvin A. Arens, Randal J. Elder, Mark S. Beasley and Chris E. Hogan', 'Accounting', 2, 4600.00, 2, 130),
  ('A6', '978-1259916977', 'Advanced Accounting', 'Joe B. Hoyle, Thomas F. Schaefer and Timothy S. Doupnik', 'Accounting', 2, 4700.00, 2, 120),
  ('A7', '978-0135572832', 'Accounting Information Systems', 'Marshall B. Romney and Paul John Steinbart', 'Accounting', 2, 4300.00, NULL, 100),
  ('A8', '978-1119493631', 'Financial Accounting: Tools for Business Decision Making', 'Paul D. Kimmel, Jerry J. Weygandt and Donald E. Kieso', 'Accounting', 3, 4200.00, NULL, 85),
  ('A9', NULL, 'Basic Financial Accounting and Reporting', 'Win Ballada and Susan Ballada', 'Accounting', 5, 650.00, NULL, 170),
  ('A10', NULL, 'Income Taxation', 'Rex B. Banggawan', 'Accounting', 4, 700.00, NULL, 60),
  ('B1', '978-0134527604', 'Management', 'Stephen P. Robbins and Mary Coulter', 'Business and Management', 4, 4200.00, NULL, 170),
  ('B2', '978-0134492513', 'Principles of Marketing', 'Philip Kotler and Gary Armstrong', 'Business and Management', 4, 4200.00, NULL, 160),
  ('B3', '978-0133856460', 'Marketing Management', 'Philip Kotler and Kevin Lane Keller', 'Business and Management', 2, 4400.00, NULL, 150),
  ('B4', '978-0134729329', 'Organizational Behavior', 'Stephen P. Robbins and Timothy A. Judge', 'Business and Management', 3, 4300.00, NULL, 140),
  ('B5', '978-0134130422', 'Operations Management: Sustainability and Supply Chain Management', 'Jay Heizer, Barry Render and Chuck Munson', 'Business and Management', 2, 4400.00, NULL, 130),
  ('B6', '978-0134739724', 'Human Resource Management', 'Gary Dessler', 'Business and Management', 3, 4200.00, NULL, 120),
  ('B7', '978-0134167848', 'Strategic Management: Concepts and Cases', 'Fred R. David and Forest R. David', 'Business and Management', 2, 4300.00, NULL, 110),
  ('B8', '978-1305576247', 'Entrepreneurship: Theory, Process, and Practice', 'Donald F. Kuratko', 'Business and Management', 2, 4100.00, NULL, 90),
  ('B9', '978-0066620992', 'Good to Great', 'Jim Collins', 'Business and Management', 2, 1450.00, NULL, 70),
  ('B10', '978-0307887894', 'The Lean Startup', 'Eric Ries', 'Business and Management', 2, 1300.00, NULL, 40),
  ('X1', '978-0357038314', 'Principles of Economics', 'N. Gregory Mankiw', 'Economics', 5, 4300.00, NULL, 175),
  ('X2', '978-0073511290', 'Economics', 'Paul A. Samuelson and William D. Nordhaus', 'Economics', 2, 4200.00, NULL, 165),
  ('X3', '978-1319105990', 'Macroeconomics', 'N. Gregory Mankiw', 'Economics', 3, 4100.00, NULL, 150),
  ('X4', '978-0134184241', 'Microeconomics', 'Robert S. Pindyck and Daniel L. Rubinfeld', 'Economics', 3, 4200.00, NULL, 140),
  ('X5', '978-0393123968', 'Intermediate Microeconomics: A Modern Approach', 'Hal R. Varian', 'Economics', 2, 3900.00, NULL, 130),
  ('X6', '978-0134733821', 'The Economics of Money, Banking, and Financial Markets', 'Frederic S. Mishkin', 'Economics', 2, 4300.00, NULL, 120),
  ('X7', '978-0134519579', 'International Economics: Theory and Policy', 'Paul R. Krugman, Maurice Obstfeld and Marc J. Melitz', 'Economics', 2, 4400.00, NULL, 100),
  ('X8', '978-0133406788', 'Economic Development', 'Michael P. Todaro and Stephen C. Smith', 'Economics', 2, 4600.00, 2, 160),
  ('X9', '978-0060731335', 'Freakonomics', 'Steven D. Levitt and Stephen J. Dubner', 'Economics', 2, 950.00, NULL, 60),
  ('X10', '978-0465060733', 'Basic Economics', 'Thomas Sowell', 'Economics', 2, 1700.00, NULL, 35),
  ('U1', '978-0134774329', 'Educational Psychology', 'Anita Woolfolk', 'Education', 3, 4200.00, NULL, 170),
  ('U2', '978-1501314131', 'Pedagogy of the Oppressed', 'Paulo Freire', 'Education', 2, 900.00, NULL, 150),
  ('U3', '978-0684836317', 'Democracy and Education', 'John Dewey', 'Education', 2, 950.00, 2, 175),
  ('U4', '978-0684838281', 'Experience and Education', 'John Dewey', 'Education', 2, 650.00, NULL, 160),
  ('U5', '978-0309070362', 'How People Learn: Brain, Mind, Experience, and School', 'National Research Council', 'Education', 2, 1800.00, 2, 140),
  ('U6', '978-0345472328', 'Mindset: The New Psychology of Success', 'Carol S. Dweck', 'Education', 3, 900.00, NULL, 80),
  ('U7', '978-1416600350', 'Understanding by Design', 'Grant Wiggins and Jay McTighe', 'Education', 3, 2300.00, NULL, 120),
  ('U8', '978-0415476188', 'Visible Learning', 'John Hattie', 'Education', 2, 2600.00, 2, 110),
  ('U9', '978-0976423317', 'The First Days of School', 'Harry K. Wong and Rosemary T. Wong', 'Education', 2, 1900.00, NULL, 90),
  ('U10', '978-0415908085', 'Teaching to Transgress: Education as the Practice of Freedom', 'bell hooks', 'Education', 2, 1400.00, NULL, 100),
  ('Y1', '978-1319050627', 'Psychology', 'David G. Myers and C. Nathan DeWall', 'Psychology', 4, 4300.00, NULL, 170),
  ('Y2', '978-1260397116', 'Social Psychology', 'David G. Myers and Jean M. Twenge', 'Psychology', 3, 4200.00, NULL, 150),
  ('Y3', '978-0890425763', 'Diagnostic and Statistical Manual of Mental Disorders, Fifth Edition, Text Revision', 'American Psychiatric Association', 'Psychology', 1, 6500.00, 1, 140),
  ('Y4', '978-0374533557', 'Thinking, Fast and Slow', 'Daniel Kahneman', 'Psychology', 3, 1200.00, NULL, 100),
  ('Y5', '978-0807014271', 'Man''s Search for Meaning', 'Viktor E. Frankl', 'Psychology', 3, 650.00, NULL, 120),
  ('Y6', '978-0134419695', 'Development Through the Lifespan', 'Laura E. Berk', 'Psychology', 3, 4300.00, NULL, 160),
  ('Y7', '978-0061241895', 'Influence: The Psychology of Persuasion', 'Robert B. Cialdini', 'Psychology', 2, 1100.00, NULL, 70),
  ('Y8', '978-0553383713', 'Emotional Intelligence', 'Daniel Goleman', 'Psychology', 2, 1000.00, NULL, 60),
  ('Y9', NULL, 'Theories of Personality', 'Jess Feist, Gregory J. Feist and Tomi-Ann Roberts', 'Psychology', 2, 4200.00, NULL, 130),
  ('Y10', '978-1319066949', 'Abnormal Psychology', 'Ronald J. Comer and Jonathan S. Comer', 'Psychology', 2, 4300.00, NULL, 110),
  ('Z1', '978-0134642796', 'Sociology', 'John J. Macionis', 'Sociology', 4, 4200.00, NULL, 170),
  ('Z2', '978-0745696683', 'Sociology', 'Anthony Giddens and Philip W. Sutton', 'Sociology', 2, 3600.00, NULL, 150),
  ('Z3', '978-0195133738', 'The Sociological Imagination', 'C. Wright Mills', 'Sociology', 2, 1100.00, NULL, 160),
  ('Z4', '978-0205615964', 'Social Research Methods: Qualitative and Quantitative Approaches', 'W. Lawrence Neuman', 'Sociology', 1, 4600.00, 1, 140),
  ('Z5', '978-1305104945', 'The Practice of Social Research', 'Earl Babbie', 'Sociology', 2, 4300.00, NULL, 130),
  ('Z6', '978-0316017930', 'Outliers: The Story of Success', 'Malcolm Gladwell', 'Sociology', 2, 950.00, NULL, 80),
  ('Z7', '978-0743203043', 'Bowling Alone: The Collapse and Revival of American Community', 'Robert D. Putnam', 'Sociology', 2, 1200.00, 2, 175),
  ('Z8', '978-0385094023', 'The Presentation of Self in Everyday Life', 'Erving Goffman', 'Sociology', 2, 850.00, 2, 165),
  ('Z9', '978-0415278317', 'Suicide: A Study in Sociology', 'Émile Durkheim', 'Sociology', 2, 1300.00, 2, 155),
  ('Z10', '978-0140439212', 'The Protestant Ethic and the Spirit of Capitalism', 'Max Weber', 'Sociology', 2, 800.00, NULL, 100),
  ('F1', NULL, 'History of the Filipino People', 'Teodoro A. Agoncillo', 'Philippine Studies', 5, 650.00, NULL, 178),
  ('F2', NULL, 'Rizal: Life, Works and Writings of a Genius, Writer, Scientist and National Hero', 'Gregorio F. Zaide and Sonia M. Zaide', 'Philippine Studies', 5, 450.00, NULL, 175),
  ('F3', NULL, 'The Philippines: A Past Revisited', 'Renato Constantino', 'Philippine Studies', 3, 600.00, NULL, 160),
  ('F4', NULL, 'Pasyon and Revolution: Popular Movements in the Philippines, 1840-1910', 'Reynaldo C. Ileto', 'Philippine Studies', 2, 750.00, NULL, 150),
  ('F5', '978-9715501354', 'Barangay: Sixteenth-Century Philippine Culture and Society', 'William Henry Scott', 'Philippine Studies', 2, 700.00, NULL, 140),
  ('F6', '978-0345328168', 'In Our Image: America''s Empire in the Philippines', 'Stanley Karnow', 'Philippine Studies', 2, 1300.00, 2, 170),
  ('F7', NULL, 'The Revolt of the Masses: The Story of Bonifacio and the Katipunan', 'Teodoro A. Agoncillo', 'Philippine Studies', 2, 550.00, NULL, 130),
  ('F8', '978-1844670901', 'Under Three Flags: Anarchism and the Anti-Colonial Imagination', 'Benedict Anderson', 'Philippine Studies', 1, 1400.00, 1, 120),
  ('F9', NULL, 'A History of the Philippines: From Indios Bravos to Filipinos', 'Luis H. Francia', 'Philippine Studies', 2, 1250.00, NULL, 100),
  ('F10', NULL, 'The First Filipino', 'León Ma. Guerrero', 'Philippine Studies', 3, 600.00, NULL, 90),
  ('G1', NULL, 'The 1987 Constitution of the Republic of the Philippines: A Commentary', 'Joaquin G. Bernas', 'Law and Government', 2, 3200.00, NULL, 170),
  ('G2', NULL, 'Philippine Political Law', 'Isagani A. Cruz', 'Law and Government', 2, 1100.00, NULL, 160),
  ('G3', '978-0451528810', 'The Federalist Papers', 'Alexander Hamilton, James Madison and John Jay', 'Law and Government', 2, 600.00, NULL, 140),
  ('G4', '978-0140431957', 'Leviathan', 'Thomas Hobbes', 'Law and Government', 2, 750.00, 2, 150),
  ('G5', '978-0140449150', 'The Prince', 'Niccolò Machiavelli', 'Law and Government', 3, 500.00, NULL, 120),
  ('G6', '978-0140432077', 'On Liberty', 'John Stuart Mill', 'Law and Government', 2, 550.00, NULL, 110),
  ('G7', '978-0521357302', 'Two Treatises of Government', 'John Locke', 'Law and Government', 2, 1400.00, 2, 165),
  ('G8', '978-0141034539', 'The Rule of Law', 'Tom Bingham', 'Law and Government', 2, 900.00, NULL, 80),
  ('G9', '978-0307719225', 'Why Nations Fail: The Origins of Power, Prosperity, and Poverty', 'Daron Acemoglu and James A. Robinson', 'Law and Government', 2, 1200.00, NULL, 60),
  ('G10', NULL, 'Criminal Law: The Revised Penal Code, Book One', 'Luis B. Reyes', 'Law and Government', 3, 1500.00, NULL, 100),
  ('H1', '978-0323677721', 'Fundamentals of Nursing', 'Patricia A. Potter, Anne Griffin Perry, Patricia A. Stockert and Amy M. Hall', 'Health Sciences', 5, 4400.00, NULL, 178),
  ('H2', '978-1496347992', 'Brunner & Suddarth''s Textbook of Medical-Surgical Nursing', 'Janice L. Hinkle and Kerry H. Cheever', 'Health Sciences', 2, 6200.00, 2, 170),
  ('H3', '978-1496348135', 'Maternal and Child Health Nursing', 'JoAnne Silbert-Flagg and Adele Pillitteri', 'Health Sciences', 2, 4400.00, NULL, 150),
  ('H4', '978-0323528900', 'Anatomy & Physiology', 'Kevin T. Patton and Gary A. Thibodeau', 'Health Sciences', 4, 4400.00, NULL, 165),
  ('H5', '978-0323597128', 'Guyton and Hall Textbook of Medical Physiology', 'John E. Hall and Michael E. Hall', 'Health Sciences', 1, 6800.00, 1, 160),
  ('H6', '978-0323353175', 'Robbins Basic Pathology', 'Vinay Kumar, Abul K. Abbas and Jon C. Aster', 'Health Sciences', 1, 5600.00, 1, 150),
  ('H7', '978-0323393041', 'Gray''s Anatomy for Students', 'Richard L. Drake, A. Wayne Vogl and Adam W. M. Mitchell', 'Health Sciences', 2, 5400.00, 2, 140),
  ('H8', '978-1260452310', 'Basic & Clinical Pharmacology', 'Bertram G. Katzung', 'Health Sciences', 1, 5200.00, 1, 130),
  ('H9', NULL, 'Medical Terminology: A Short Course', 'Davi-Ellen Chabner', 'Health Sciences', 3, 2800.00, NULL, 110),
  ('H10', '978-0323529495', 'Pharmacology and the Nursing Process', 'Linda Lane Lilley, Shelly Rainforth Collins and Julie S. Snyder', 'Health Sciences', 3, 4300.00, NULL, 120),
  ('I1', '978-0135188743', 'Campbell Biology', 'Lisa A. Urry, Michael L. Cain, Steven A. Wasserman, Peter V. Minorsky and Rebecca B. Orr', 'Biology', 5, 4450.00, NULL, 175),
  ('I2', '978-0393884821', 'Molecular Biology of the Cell', 'Bruce Alberts, Rebecca Heald, Alexander Johnson, David Morgan, Martin Raff, Keith Roberts and Peter Walter', 'Biology', 1, 6900.00, 1, 160),
  ('I3', '978-1319228002', 'Lehninger Principles of Biochemistry', 'David L. Nelson and Michael M. Cox', 'Biology', 2, 5300.00, 2, 150),
  ('I4', '978-0134580999', 'Human Anatomy & Physiology', 'Elaine N. Marieb and Katja Hoehn', 'Biology', 4, 4400.00, NULL, 170),
  ('I5', '978-0134605180', 'Microbiology: An Introduction', 'Gerard J. Tortora, Berdell R. Funke and Christine L. Case', 'Biology', 3, 4300.00, NULL, 140),
  ('I6', '978-0134604718', 'Concepts of Genetics', 'William S. Klug, Michael R. Cummings, Charlotte A. Spencer, Michael A. Palladino and Darrell J. Killian', 'Biology', 2, 4400.00, NULL, 130),
  ('I7', '978-0393680362', 'Essential Cell Biology', 'Bruce Alberts, Karen Hopkin, Alexander Johnson, David Morgan, Martin Raff, Keith Roberts and Peter Walter', 'Biology', 2, 4200.00, NULL, 120),
  ('I8', '978-0134261928', 'Brock Biology of Microorganisms', 'Michael T. Madigan, Kelly S. Bender, Daniel H. Buckley, W. Matthew Sattley and David A. Stahl', 'Biology', 1, 5100.00, 1, 110),
  ('I9', '978-1260169614', 'Biology', 'Peter H. Raven, George B. Johnson, Kenneth A. Mason, Jonathan B. Losos and Tod Duncan', 'Biology', 2, 4400.00, NULL, 100),
  ('I10', NULL, 'Ecology: Concepts and Applications', 'Manuel C. Molles Jr. and Anna A. Sher', 'Biology', 2, 4300.00, NULL, 90),
  ('K1', '978-0134414232', 'Chemistry: The Central Science', 'Theodore L. Brown, H. Eugene LeMay, Bruce E. Bursten, Catherine J. Murphy, Patrick M. Woodward and Matthew E. Stoltzfus', 'Chemistry', 5, 4450.00, NULL, 176),
  ('K2', '978-1259911156', 'Chemistry', 'Raymond Chang and Jason Overby', 'Chemistry', 4, 4300.00, NULL, 165),
  ('K3', '978-1305080485', 'Organic Chemistry', 'John McMurry', 'Chemistry', 3, 4400.00, NULL, 150),
  ('K4', '978-1118875766', 'Organic Chemistry', 'T. W. Graham Solomons, Craig B. Fryhle and Scott A. Snyder', 'Chemistry', 2, 4400.00, NULL, 140),
  ('K5', '978-0198769866', 'Atkins'' Physical Chemistry', 'Peter Atkins, Julio de Paula and James Keeler', 'Chemistry', 1, 4900.00, 1, 135),
  ('K6', '978-1319164300', 'Quantitative Chemical Analysis', 'Daniel C. Harris and Charles A. Lucy', 'Chemistry', 2, 4600.00, 2, 125),
  ('K7', '978-0321811059', 'Inorganic Chemistry', 'Gary L. Miessler, Paul J. Fischer and Donald A. Tarr', 'Chemistry', 1, 4700.00, 1, 120),
  ('K8', '978-0132931281', 'General Chemistry: Principles and Modern Applications', 'Ralph H. Petrucci, F. Geoffrey Herring, Jeffry D. Madura and Carey Bissonnette', 'Chemistry', 3, 4400.00, NULL, 110),
  ('K9', '978-0495558286', 'Fundamentals of Analytical Chemistry', 'Douglas A. Skoog, Donald M. West, F. James Holler and Stanley R. Crouch', 'Chemistry', 2, 4400.00, NULL, 100),
  ('K10', '978-0134874371', 'Chemistry: A Molecular Approach', 'Nivaldo J. Tro', 'Chemistry', 3, 4300.00, NULL, 80),
  ('Q1', '978-1118230718', 'Fundamentals of Physics', 'David Halliday, Robert Resnick and Jearl Walker', 'Physics', 5, 4450.00, NULL, 177),
  ('Q2', '978-0135159552', 'University Physics with Modern Physics', 'Hugh D. Young and Roger A. Freedman', 'Physics', 4, 4450.00, NULL, 165),
  ('Q3', '978-1337553278', 'Physics for Scientists and Engineers', 'Raymond A. Serway and John W. Jewett Jr.', 'Physics', 3, 4400.00, NULL, 155),
  ('Q4', '978-0321909107', 'Conceptual Physics', 'Paul G. Hewitt', 'Physics', 3, 4100.00, NULL, 145),
  ('Q5', '978-1305952300', 'College Physics', 'Raymond A. Serway and Chris Vuille', 'Physics', 3, 4300.00, NULL, 135),
  ('Q6', '978-1108420419', 'Introduction to Electrodynamics', 'David J. Griffiths', 'Physics', 2, 3900.00, NULL, 125),
  ('Q7', '978-1107189638', 'Introduction to Quantum Mechanics', 'David J. Griffiths and Darrell F. Schroeter', 'Physics', 2, 3800.00, NULL, 115),
  ('Q8', '978-0201657029', 'Classical Mechanics', 'Herbert Goldstein, Charles P. Poole and John L. Safko', 'Physics', 1, 4800.00, 1, 170),
  ('Q9', '978-1118061145', 'Modern Physics', 'Kenneth S. Krane', 'Physics', 2, 4600.00, 2, 105),
  ('Q10', '978-0465024933', 'The Feynman Lectures on Physics, Volume I', 'Richard P. Feynman, Robert B. Leighton and Matthew Sands', 'Physics', 2, 3600.00, 2, 95),
  ('W1', '978-0205309023', 'The Elements of Style', 'William Strunk Jr. and E. B. White', 'Languages', 4, 550.00, NULL, 175),
  ('W2', '978-1108457651', 'English Grammar in Use', 'Raymond Murphy', 'Languages', 4, 1900.00, NULL, 160),
  ('W3', '978-0877798095', 'Merriam-Webster''s Collegiate Dictionary', 'Merriam-Webster', 'Languages', 2, 1800.00, NULL, 150),
  ('W4', '978-0194202411', 'Practical English Usage', 'Michael Swan', 'Languages', 2, 2100.00, NULL, 140),
  ('W5', '978-0393631678', 'They Say / I Say: The Moves That Matter in Academic Writing', 'Gerald Graff and Cathy Birkenstein', 'Languages', 3, 1300.00, NULL, 120),
  ('W6', '978-0060891541', 'On Writing Well', 'William Zinsser', 'Languages', 2, 900.00, NULL, 100),
  ('W7', '978-0226287058', 'The Chicago Manual of Style', 'The University of Chicago Press', 'Languages', 1, 4200.00, 1, 170),
  ('W8', NULL, 'Publication Manual of the American Psychological Association', 'American Psychological Association', 'Languages', 2, 3300.00, NULL, 90),
  ('W9', NULL, 'UP Diksiyonaryong Filipino', 'Virgilio S. Almario', 'Languages', 2, 1800.00, NULL, 165),
  ('W10', '978-0385348119', 'Fluent Forever', 'Gabriel Wyner', 'Languages', 2, 1200.00, NULL, 60),
  ('R1', '978-0140455113', 'The Republic', 'Plato', 'Humanities', 3, 600.00, NULL, 170),
  ('R2', '978-0199213610', 'The Nicomachean Ethics', 'Aristotle', 'Humanities', 2, 700.00, NULL, 160),
  ('R3', '978-0812968255', 'Meditations', 'Marcus Aurelius', 'Humanities', 3, 750.00, NULL, 120),
  ('R4', '978-1337630702', 'Gardner''s Art Through the Ages: A Global History', 'Fred S. Kleiner', 'Humanities', 1, 6800.00, 1, 150),
  ('R5', '978-0714832470', 'The Story of Art', 'E. H. Gombrich', 'Humanities', 2, 2600.00, 2, 175),
  ('R6', '978-0140135152', 'Ways of Seeing', 'John Berger', 'Humanities', 2, 700.00, NULL, 130),
  ('R7', '978-0671201586', 'A History of Western Philosophy', 'Bertrand Russell', 'Humanities', 2, 1300.00, 2, 165),
  ('R8', '978-0374530716', 'Sophie''s World', 'Jostein Gaarder', 'Humanities', 3, 900.00, NULL, 90),
  ('R9', '978-0374532505', 'Justice: What''s the Right Thing to Do?', 'Michael J. Sandel', 'Humanities', 2, 950.00, NULL, 70),
  ('R10', '978-1259914256', 'The Elements of Moral Philosophy', 'James Rachels and Stuart Rachels', 'Humanities', 2, 3200.00, NULL, 110);

DO $$
DECLARE
  v_admin int := (SELECT staff_id FROM staff WHERE position = 'admin' ORDER BY staff_id LIMIT 1);
  r record;
  v_id int;
  n int := 0;
BEGIN
  PERFORM pg_temp.act('staff', v_admin);
  FOR r IN SELECT * FROM seed_book ORDER BY added DESC, code LOOP
    n := n + 1;
    v_id := fn_create_book(r.isbn, r.title, r.author, r.category, r.copies, r.cost, v_admin);
    IF r.lim IS NOT NULL THEN
      PERFORM fn_set_copy_limit(v_id, r.lim, v_admin);
    END IF;
    UPDATE book SET date_added = current_date - r.added WHERE book_id = v_id;
    UPDATE seed_book SET book_id = v_id WHERE code = r.code;
    -- The "added" row in the audit trail shows the book as it was really added.
    UPDATE audit_log
       SET detail = detail || jsonb_build_object('date_added', current_date - r.added, 'copy_limit', coalesce(r.lim, 5))
     WHERE audit_id > (SELECT last_id FROM seed_cursor) AND table_name = 'book' AND action = 'insert';
    PERFORM pg_temp.stamp(pg_temp.at(r.added, time '08:40' + (n % 9) * interval '37 minutes'));
  END LOOP;
END $$;

-- The date and limit moves above are part of building the catalog, not events in it.
DELETE FROM audit_log a
 WHERE a.audit_id > (SELECT audit_from FROM seed_start)
   AND a.action = 'update'
   AND NOT EXISTS (SELECT 1 FROM jsonb_object_keys(a.detail) k WHERE k NOT IN ('date_added', 'copy_limit'));
