"""Build an evidence-aligned revision without modifying the source DOCX.

Uses only the Python standard library. Original paragraph indexes are zero-based,
direct children of word/document.xml's body, before any edits.
"""
from pathlib import Path
from xml.dom import minidom
import csv
import hashlib
import json
import re
import zipfile

HERE = Path(__file__).resolve().parent
ROOT = HERE.parent.parent
SOURCE = HERE / 'rms-chapter_1&2.docx'
DEST = HERE / 'rms-chapter_1&2-revised.docx'
W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
EXPECTED_SOURCE_HASH = 'ae05d5ec6438175ca9290139c50c7aec1bdd5cd5845fdedef2939d4c2387ca31'

# Every replacement is written in full; institutional findings and evaluation
# outcomes are never invented to fill missing thesis evidence.
EDITS = {
7: 'Bachelor of Science in Information Technology',
144: 'Research management in a higher education institution involves more than storing a final manuscript. Research titles, chapter submissions, decisions, supporting files, activities, publications, and intellectual-property records must remain associated with the appropriate researchers. A centralized information system can organize these records and make their processing visible to authorized users. The practical value of such a system depends on its fit with institutional responsibilities and on evidence obtained during evaluation.',
145: 'The Eulogio “Amang” Rodriguez Institute of Science and Technology (EARIST) Research Manual (2015) provides an institutional reference for research administration. The repository copy describes research proposals, evaluation, progress and terminal reports, publication, and related institutional responsibilities. It informs the study context, but it does not by itself establish the current procedures used at the Cavite Campus or prove that the implemented software reproduces every institutional form and approval stage.',
146: 'This study concerns the web-based Research Management System (RMS) for EARIST Cavite Campus. In the current implementation, students and faculty use a shared workflow: create a research-title draft, submit a locked version, receive an authorized review decision, revise and resubmit when returned, and proceed to Chapters 1–5 after title approval. Each later chapter becomes available after the preceding chapter is approved. Formal title and chapter decisions are performed by administrators and research staff who hold the relevant action permission.',
147: 'The requirements investigation should establish how campus personnel currently submit, review, monitor, and retrieve research records. The following are problem areas to investigate through interviews, observation, and institutional records; their frequency and severity at EARIST Cavite Campus must be established by the study rather than inferred from the software:',
148: 'Submission and Review Coordination: Researchers and authorized personnel need a clear record of what was submitted, which version is awaiting a decision, and what recommendations must be addressed. Where paper or disconnected files are used, the study should document the actual handoffs and processing times.',
149: 'Progress Visibility and Accountability: Students, faculty researchers, advisers, and research personnel need access appropriate to their responsibilities. A useful system must distinguish editable drafts from submitted versions, retain decisions and recommendations, and show chapter progress without giving every user review authority.',
150: 'Record Organization and Retrieval: Research metadata, supporting documents, publications, and intellectual-property records need consistent organization and appropriate access controls. The study should establish existing retrieval practices and measure representative retrieval tasks before claiming an improvement.',
151: 'RMS addresses these needs through browser-based interfaces, server-side processing, and relational data storage. Its implemented stack uses HTML, CSS, JavaScript, PHP, and MySQLi for database access. The project is developed locally using XAMPP. Centralized records and backend controls support the intended workflow; security, reliability, usability, and efficiency remain matters to evaluate through defined tasks and technical checks.',
152: 'Accordingly, this study aims to document, develop, and evaluate RMS as a platform for student and faculty research management. Its capabilities span research capacity-building activities, title and chapter development, implementation and output monitoring, publication and dissemination, and intellectual-property and utilization records. Evaluation is planned with administrators, authorized research staff, faculty users, and student researchers using selected ISO/IEC 25010:2011 product-quality characteristics. These chapters present the study rationale and evaluation plan; they do not report completed acceptance testing or measured benefits.',
156: 'This study addresses the organization of research submission, permission-controlled review, chapter tracking, and related records at EARIST Cavite Campus through a web-based Research Management System.',
158: 'What challenges and requirements do administrators, research staff, faculty users, and student researchers identify in current research submission, review, progress monitoring, and record retrieval practices?',
159: 'How should the system organize student and faculty research, sequential title-and-chapter processing, authorized decisions, version history, and associated activity, publication, and intellectual-property records?',
165: 'How do the selected users assess the developed system using an instrument based on the following selected ISO/IEC 25010:2011 product-quality characteristics, supported by appropriate technical evidence?',
171: 'What differences in submission processing time, visibility of review and chapter status, and research-record retrieval can be observed between documented existing practices and defined tasks using RMS?',
179: 'To document, develop, and evaluate a web-based Research Management System for EARIST Cavite Campus that centralizes student and faculty research records, supports permission-controlled title and chapter processing, and organizes related research activities and outputs.',
182: 'To document current campus research-administration practices and requirements through authorized interviews, observation, and institutional documents, using the EARIST Research Manual as contextual evidence rather than assuming complete equivalence with the software;',
183: 'To align the architecture, database, user interfaces, role permissions, and title-and-chapter workflow with the documented requirements and current implementation;',
184: 'To develop and document the following connected capabilities using HTML, CSS, JavaScript, PHP, and a MySQL-compatible relational database accessed through MySQLi:',
185: 'User management, authentication, and role- and action-based access controls;',
186: 'Shared student and faculty title submission with editable drafts, locked submitted versions, and retained submission history;',
187: 'Sequential Chapters 1–5, recommendations, revision and resubmission, and computed chapter-progress indicators;',
188: 'Administrator and specifically authorized staff review and approval, supported by decision records and in-app notifications;',
189: 'Research capacity-building activities, implementation and output listings, searchable research archives, publications, and intellectual-property and utilization records; and',
190: 'Administrative summaries, reporting, and activity logging within the implemented permissions;',
191: 'To verify representative workflows, persistence, invalid input handling, direct-route authorization, submission locks, and version preservation through focused technical and integration checks, followed by user acceptance testing. Mutating browser tests must use a verified isolated disposable database. Individual test outcomes will be reported only after execution;',
192: 'To evaluate selected product-quality characteristics—Functional Suitability, Usability, Performance Efficiency, Reliability, and Security—using ISO/IEC 25010:2011 as the stated evaluation basis, combining respondent assessments with relevant technical observations; and',
193: 'To compare documented baseline and RMS task results for submission processing, status visibility, and record retrieval, reporting the sample, task conditions, measurements, and limitations without inferring time savings from satisfaction ratings alone.',
197: 'The system scope covers the currently implemented web-based RMS for student and faculty research at EARIST Cavite Campus. It includes research capacity building, research development, implementation and outputs, publication and dissemination, and intellectual-property and utilization records. The primary workflow evaluated is title submission and sequential Chapters 1–5. Supporting modules must be evaluated separately before conclusions are extended to them. The final respondent sample and task coverage will be specified in the methodology.',
198: 'Users: The platform uses four account roles—administrator, research staff, faculty, and student—with dedicated navigation and access controls. Access depends on the role, assigned action permissions, and the ownership or assignment rules of the relevant record:',
200: 'Administrator: Manages accounts and permissions, oversees research records, and performs formal title and chapter review or approval. Software access does not establish that every administrator account corresponds to a particular institutional officeholder.',
201: 'Research Staff: Uses only the modules and actions explicitly granted through the existing permission system. A permission to review and return a submission is distinct from permission to approve it; staff do not receive unrestricted review authority by default.',
202: 'Faculty: May submit and track their own research through the canonical title-and-chapter workflow. Assigned advisers may view and guide relevant student research where supported. Adviser assignment alone does not authorize formal title or chapter decisions.',
203: 'Student: Creates and edits eligible research drafts, submits titles and sequential chapters, views decisions and recommendations, and revises returned work. Access to documents and associated records remains subject to the applicable backend rules.',
205: 'Location / Organization: The intended institutional setting is EARIST Cavite Campus. The EARIST Research Manual (2015) is a contextual reference. Confirmation of present campus procedures and any claimed equivalence with OVPREIS forms requires review by the appropriate institutional personnel.',
207: 'Registration, authentication, session handling, and backend authorization controls;',
208: 'Research-title metadata, researcher and project-member information, chapter content, and supporting document records;',
209: 'Controlled document uploads and downloads. The chapter interface accepts PDF, DOC, and DOCX files subject to its configured validation and limits; other modules may impose different file requirements;',
210: 'Project identifiers, submission-version numbers, submission timestamps, and retained review history for traceability;',
211: 'Title and chapter statuses reflecting drafts, submitted work, returned revisions, and approval. Chapter-progress displays derive approved-chapter counts; these displays must be distinguished from stored project status;',
212: 'Authorized review interfaces that preserve the submitted snapshot, reviewer identity, decision, and recommendations;',
213: 'Searchable research records and archive views with the filters actually provided by each interface. Publication-repository views and completed-research archives serve related but distinct record sets;',
214: 'In-app notifications in the inspected title-submission and review paths. PHPMailer is a declared dependency used by separate email features; delivery of an email for every workflow event is outside the verified claim;',
215: 'Administrative summaries and status or chapter-progress indicators based on the available records and permissions; and',
216: 'Activity logging and separate submission/review history. Logging coverage must be checked per action before making claims about complete audit coverage or tamper resistance.',
218: 'User Management and Authentication: Account access, authenticated sessions, and role- and action-based permissions.',
219: 'Research Capacity Building and Development: Seminar, training, and workshop records; shared student/faculty title drafts; locked submission versions; and formal review by authorized personnel.',
220: 'Chapter Tracking, Implementation, and Outputs: Sequential chapter submissions after title approval, recorded recommendations and revisions, computed progress, and ongoing/completed research and output listings.',
221: 'Publication and Dissemination: Journal publication and conference presentation records and publication-repository views, together with the separate research archive interfaces.',
222: 'Intellectual Property and Utilization: Internal records for copyrights, patents and utility models, innovation, and technology transfer. These features do not establish electronic filing with an external intellectual-property agency.',
223: 'Administration and Reporting: Role-specific dashboards, administrative reports, permission management, activity logs, and record oversight.',
225: 'Frontend: HTML, CSS, and JavaScript, with custom role-specific page shells and styling;',
226: 'Backend: PHP with MySQLi database access and prepared statements in the inspected workflow handlers;',
227: 'Database: A MySQL-compatible relational schema. The deployed database engine and version should be recorded during evaluation rather than inferred from the XAMPP label;',
228: 'Declared Composer packages: PHPMailer and phpdotenv. Bootstrap, DataTables, Chart.js, and Dompdf are not part of the verified implementation description; and',
229: 'Development Environment: XAMPP providing the local Apache/PHP/database environment. Production deployment characteristics are outside this source-based description.',
232: 'Data Covered: Account and profile records; research-title and chapter data; project membership and adviser links; submission snapshots and attachment references; review decisions and recommendations; research activities; publication records; intellectual-property and utilization records; in-app notifications; and activity logs. Visibility and modification rights depend on the relevant record and permission rules.',
235: 'Platform Boundary: RMS is accessed through a web browser. The study does not include development of a native Android/iOS application or an offline desktop client. Network availability is required between the browser and application server; public internet access is not inherently required for a local deployment.',
236: 'Workflow and Administrative Boundary: The implementation supports student and faculty research and internal output records. It does not establish full automation of CREC/EREC routing, all OVPREIS Forms 1–6, research funding, honoraria, budget liquidation, or external patent filing. Institutional form compliance must be assessed using a separate field-and-action mapping.',
238: 'Functional Exclusions: The study does not claim an integrated plagiarism or grammar-analysis engine, inline document annotation, automatic approval, or guaranteed protection against all security threats. Scientific judgments and external originality checks remain responsibilities of the appropriate personnel.',
239: 'Integration Boundary: No integration with institutional enrollment, grading, cashiering, journal-publisher, or external intellectual-property filing services is established by this review. Internal publication and IP records must not be described as those external transactions.',
240: 'Infrastructure and Preservation Boundary: Availability and response time depend on the deployment, browser, network, file sizes, and workload. Repository storage alone does not guarantee permanent preservation, successful restoration, or disaster recovery; these require an established operational process and verification.',
241: 'Evaluation Boundary: Planned participants are administrators, research staff, faculty users, and students associated with the study setting. Only tasks, modules, user groups, and conditions actually included in the approved evaluation may support the resulting conclusions. Institutional baseline findings, respondent counts, and completed quality ratings are not supplied in these chapters.',
244: 'The system is intended to support the following stakeholders. The benefits described here are expected contributions to be assessed during evaluation rather than measured outcomes already established by the study:',
245: 'Administrators: Consolidates research records and formal review decisions, supports permission assignment, and provides summaries for institutional oversight.',
246: 'Research Staff: Provides access to the administrative tasks explicitly assigned to them and organizes relevant submission, review, activity, and output records.',
247: 'Faculty Researchers and Advisers: Supports faculty-owned research submissions and chapter tracking, while retaining assigned-adviser visibility and guidance where available. Formal approval remains with the authorized administrator or staff account.',
249: 'Student Researchers: Provides an interface for drafting and submitting research titles and chapters, viewing recommendations, revising returned work, and tracking recorded progress.',
250: 'EARIST Cavite Campus: Provides a basis for more coordinated research administration and associated activity, publication, and intellectual-property records. Claims of reduced costs, shorter turnaround, or reduced record loss require study evidence.',
251: 'Future Researchers and Developers: Provides a documented implementation and evaluation basis for studying permission-controlled academic workflows, version preservation, and research-record organization.',
260: 'Chapter Submission: A researcher-owned chapter record that can be drafted and, when eligible, submitted as a preserved version for authorized review. Later chapters require approval of the preceding chapter.',
261: 'Digital Repository / Research Archive: Searchable views of eligible research or publication records and associated metadata. Access and document availability depend on the relevant interface and permission rules; permanent preservation is not implied.',
262: 'CREC and EREC: Institutional research-evaluation committees described in the EARIST Research Manual. Their existence in institutional policy does not establish a separate committee-account role or fully automated committee sequence in the current software.',
264: 'EARIST Research Manual (2015): The institutional research-policy reference available in the project repository. Current campus applicability and any form-to-software mapping require institutional confirmation.',
265: 'Faculty Researcher / Adviser: A faculty account may own research and use the shared submission workflow. Adviser assignment supports guidance and relevant project visibility, and does not itself grant formal title or chapter approval authority.',
266: 'ISO/IEC 25010:2011: The identified edition used as the proposed basis for five selected product-quality characteristics in this study. It is not a 2017 edition, and its use does not establish certification or completed evaluation.',
267: 'MySQLi: The PHP database extension used by the inspected application to communicate with its MySQL-compatible relational database, including prepared statements.',
268: 'Title Submission: A student or faculty researcher submits a title draft as a preserved version for review. Approval of the title is required before chapter submission becomes available.',
269: 'OVPREIS Forms: Institutional research forms described in the EARIST Research Manual. They provide context for requirements analysis; complete digitization and routing equivalence are not assumed in this study.',
271: 'Progress Tracking: Display of recorded chapter statuses and counts or percentages derived from chapter approvals. It is distinguished from stored project status and is not a claim of push-based live updates.',
272: 'Administrator: An account role with research-management and formal review authority in the application. The title of the corresponding institutional officeholder must be established separately.',
273: 'Authorized Research Staff: A research-staff account granted the module and action permissions needed for a particular task, including separate permissions for review and approval.',
274: 'Role-Based Access Control: Restriction of application actions by account role, supplemented in RMS by explicit staff action permissions and relevant ownership or assignment checks.',
275: 'Student Researcher: A student account that uses RMS to create and submit eligible research work, view recorded decisions, and revise returned submissions.',
276: 'Submission Version and Lock: A preserved snapshot of submitted title or chapter data and associated attachment references. Submitted work is not editable through the ordinary draft-editing path; a returned submission can be revised and resubmitted as a later version.',
286: 'This chapter reviews literature, verified related studies, and documented system capabilities relevant to the updated RMS. It distinguishes external research findings from features observed in the project implementation. It then relates the reviewed material to the study’s acceptance perspective, architectural and access-control basis, product-quality evaluation, and Input–Process–Output framework.',
289: 'The review covers information systems in higher education, browser-based application architecture, relational records, repository design, digitalization, access control, and software evaluation. Sources are used for claims they actually support; results from another institution are not treated as evidence of effectiveness at EARIST Cavite Campus.',
293: 'Information systems connect organizational activities, information, and management decisions. Laudon and Laudon (2020) provide an overview of management information systems and their relationship to organizational performance. For the present study, this perspective supports examining how research records, responsibilities, and decisions fit together, rather than evaluating the application solely as a set of screens.',
294: 'Brooks and McCormack (2020) distinguish the conversion of information to digital form from changes to operational processes and wider institutional transformation. RMS can be examined as an intervention in research-record processing. Whether it produces broader organizational change must be established through implementation and evaluation evidence, including user practices and institutional support.',
296: 'The implemented application can be described using three logical responsibilities: presentation in a browser, application processing on the PHP server, and persistence in a relational database. This is a description of the inspected system, not a claim that the code or deployment enforces three independently deployed tiers. Requests must be validated and authorized where they are processed on the server.',
297: 'Presentation: HTML, CSS, and JavaScript provide the forms, record views, role navigation, and progress indicators. The current project uses custom styling and role-specific shells. A responsive interface is an implementation goal to verify on representative devices, not evidence that a particular framework is installed.',
301: 'Application Processing: PHP handles requests, authenticated sessions, permitted actions, validation, and document operations. Canonical title and chapter submission pages are shared by student and faculty researchers. The review helper separates administrator authority from research-staff permissions and records decisions against preserved submission versions.',
303: 'Data Storage: A MySQL-compatible relational database stores linked account, project, chapter, version, review, activity, publication, and intellectual-property records. Supporting files are associated with database metadata. This separation requires checking both the record and attachment access path when validating authorization.',
311: 'Relational database design organizes related records through keys and constraints. Elmasri and Navathe (2016) cover database modeling, relational design, and implementation concepts. These concepts are relevant to maintaining associations among researchers, projects, chapters, submitted versions, decisions, and outputs without replacing history with a single mutable record.',
312: 'The inspected RMS uses MySQLi rather than PDO. The PHP Group (n.d.) documents prepared statements in which values are bound separately from the SQL statement. This technique supports safe handling of input values, but does not replace authorization, validation, output escaping, or file-access controls. The thesis therefore treats security as a set of behaviors to verify rather than a property guaranteed by selecting PHP and MySQL.',
316: 'Research records change through drafting, submission, review, and revision. A record-management design must distinguish the version currently being edited from the version already submitted for a decision. In RMS, submission snapshots, attachment links, reviewer decisions, and recommendations provide the implementation basis for tracing these changes. Their persistence and access restrictions require direct verification.',
317: 'Smith et al. (2003) describe DSpace as an institutional repository for digital research and educational material, including submission workflow, metadata, retrieval, and preservation concerns. This literature supports organized repository services. It does not establish that RMS implements DSpace, Dublin Core compliance, preservation guarantees, or the same submission workflow.',
321: 'Brooks and McCormack (2020) emphasize that digital transformation includes coordinated organizational change as well as technology. For RMS, replacing a handoff with an online action still requires clear responsibility, access, user preparation, and an operational environment. The study will examine these conditions rather than equating installation of the application with institution-wide transformation.',
322: 'Campus-specific claims require campus-specific evidence. The Research Manual supplies institutional context, while interviews, observations, and approved records should establish how the Cavite Campus currently manages research. Published experiences from other settings can inform questions and design choices but cannot establish local processing delays, paper consumption, or record-loss rates.',
326: 'The proposed evaluation retains five selected characteristics from ISO/IEC 25010:2011: Functional Suitability, Usability, Performance Efficiency, Reliability, and Security (ISO/IEC, 2011). This limited selection focuses the study on the intended tasks; it is not an assessment of the entire model. The 2011 edition has been withdrawn, and a revised product-quality model is available as ISO/IEC 25010:2023 (ISO/IEC, 2023). The final methodology and instrument must use one edition consistently.',
327: 'Functional Suitability: Check whether representative authorized users can complete the required tasks, including title submission, sequential chapters, review, revision, and record retrieval, and whether results and stored records match the stated requirements.',
328: 'Performance Efficiency: Measure response or task times under documented hardware, network, workload, and data conditions. User perceptions may supplement these observations but cannot establish throughput or resource-use performance by themselves.',
329: 'Usability: Observe representative users completing defined tasks and collect structured assessments of clarity, ease of learning, and ease of operation. Identify errors, assistance needed, and confusing states.',
330: 'Reliability: Exercise representative failed or invalid operations and assess whether records remain consistent and recoverable within the tested conditions. Availability and recovery claims need operational evidence beyond a successful page load.',
331: 'Security: Check permitted and prohibited actions, record ownership or assignment, direct-route document access, CSRF handling, input validation, and submitted-version locks. Survey responses alone cannot establish security effectiveness.',
332: 'The chosen characteristics guide the evaluation plan. The existence of source code and test infrastructure does not demonstrate that an acceptance study has been completed, that every characteristic has passed, or that the application has been certified against the standard.',
334: 'Foreign Studies and Foundational Research',
335: '1. Institutional Repository Design (Smith et al., 2003)',
336: 'Smith et al. (2003) describe the design and early implementation of DSpace at MIT, including management of research material and institutional submission workflows. The work is relevant to the separation of deposit, review, discovery, and preservation responsibilities. For RMS, repository services should be assessed alongside the title-and-chapter workflow; the source does not support describing all repositories as lacking review or submission processing.',
337: '2. Role-Based Authorization (Ferraiolo & Kuhn, 1992)',
338: 'Ferraiolo and Kuhn (1992) present role-based access control as a way to organize permissions around organizational responsibilities. This provides a foundation for distinguishing researcher, administrator, and research-staff actions. In RMS, specific staff permissions and ownership rules supplement the account role. A role-based design still requires tests of prohibited actions and direct access to protected records.',
341: '3. Digital Transformation in Higher Education (Brooks & McCormack, 2020)',
342: 'The EDUCAUSE report by Brooks and McCormack (2020) examines digital transformation in higher education. It distinguishes operational digitalization from wider institutional change. This distinction informs the study boundary: RMS provides research-administration capabilities, while adoption, revised work practices, and institutional outcomes must be investigated. The report is not evidence of a measured reduction in EARIST processing time.',
343: '4. Electronic Research Ethics Management (Mkumbwa et al., 2025)',
344: 'Mkumbwa et al. (2025) interviewed 16 faculty users of an electronic research ethics management system in Tanzania. Participants described convenience and better record management, alongside dependence on ICT resources and limits on reviewer–researcher interaction. These findings inform evaluation of access, infrastructure, and feedback in RMS. Ethical-clearance processing in that setting differs from the title-and-chapter decisions implemented here.',
345: '5. Technology Acceptance Research (Venkatesh et al., 2016)',
346: 'Venkatesh, Thong, and Xu (2016) review UTAUT research and discuss the importance of context in technology acceptance. This is background on acceptance research, not the original TAM study. The present study uses Davis (1989) for perceived usefulness and perceived ease of use, and does not claim to test UTAUT or its complete set of constructs.',
348: 'Local Studies and Institutional Context',
349: '1. University of the East Research Management System (Celis & Lagda, 2025)',
350: 'Celis and Lagda (2025) describe development and evaluation of the University of the East RMS. The publicly available abstract identifies submission, approval, research-profile, and reporting functions and an evaluation involving 50 respondents. It also reports requests for improved validation and interfaces. This is a relevant Philippine comparator, but its respondent ratings are evidence about that system, not measured results for EARIST RMS. This review uses the abstract; the full article was not available without login.',
351: '2. Bulacan State University Research Management Platform (Adriano et al., 2025)',
352: 'Adriano et al. (2025) describe a Bulacan State University research-management platform using natural-language processing and an analytics dashboard. Their descriptive-developmental study reports consultations, interviews, questionnaire data, and quality assessments. It supports examining research records and user feedback in a Philippine setting. RMS does not thereby acquire the comparator’s natural-language-processing capabilities, and the comparator’s evaluation does not establish EARIST outcomes.',
356: '3. Institutional Reference: EARIST Research Manual (2015)',
357: 'The repository copy of the EARIST Research Manual describes research administration, evaluation committees, standardized forms, and research-related activities and outputs. It is an institutional reference rather than an empirical evaluation of RMS. It should guide requirements discussions without being used to assert that current campus practice is entirely paper-based or that the application automates every prescribed committee action.',
358: '4. Implications for the Present Study',
359: 'The verified local studies demonstrate that Philippine institutions have investigated research-management platforms with different functional boundaries. The present study must describe its own role model, title-and-chapter processing, record safeguards, and supporting modules. Local effectiveness claims require observations and evaluation data from the actual EARIST study participants and deployment.',
360: '5. Delimited Research Contribution',
361: 'The contribution proposed here is an implementation and evaluation of an EARIST-oriented RMS with shared student/faculty submissions, permission-controlled decisions, preserved versions, sequential chapters, and associated research records. It is not a claim that no comparable system exists. Additional local literature may be added only after its bibliographic identity and attributed findings are verified.',
368: 'Table 2.1 compares documented capabilities and the boundaries relevant to RMS. Capabilities of other systems are drawn from the cited study abstracts or official product documentation. The comparison does not establish procurement costs, superiority, or institutional compliance without corresponding evidence.',
370: 'The comparison separates research administration, repository services, and general classroom coordination. DSpace includes submission workflows and must not be described as a final-file store with no review capability. EPrints is a repository platform whose local configuration must be considered. Google Classroom supports educational workflows but its official overview does not establish an EARIST-specific title-and-chapter process. RMS is distinguished here by its inspected implementation and intended institutional setting, not by unsupported claims that every alternative lacks the same features.',
373: 'The reviewed sources support studying research management as a combination of organizational responsibilities, record processing, repository services, and evaluation. Their findings inform the design and methods, while the current code and schema establish what the implemented RMS presently contains.',
374: 'First, the information-systems and higher-education literature suggests that coordinated processes and institutional support matter alongside the technical platform. The requirements investigation therefore needs to establish actual campus practices and assign responsibility for submission, review, and record maintenance.',
375: 'Second, the verified local studies provide comparators for research-management functions and user evaluation. Their different boundaries require the present study to state explicitly which RMS modules and respondent groups it evaluates.',
376: 'Third, repository design highlights the need to distinguish discoverable metadata, submission workflow, document access, and preservation. RMS research archives and publication-repository views should be described and evaluated according to the records and access rules each provides.',
377: 'Fourth, role-based access-control research supports separating authority from simple participation in a project. For RMS, faculty researcher or adviser access does not replace administrator/staff decision permissions. Security checks must include attempted actions outside the assigned authority.',
378: 'Fifth, acceptance and product-quality perspectives address different questions. Perceived usefulness and ease of use can inform user assessments, while technical checks and task measurements support claims about correctness, performance, reliability, and security. Neither type of evidence should be substituted for the other.',
379: 'The study therefore addresses a specific implementation and evaluation problem: how to support the documented EARIST research requirements with shared submissions, preserved versions, authorized review, sequential chapters, and connected records. The evaluation must establish the extent of the system’s fit and its limitations rather than assuming complete institutional automation.',
386: 'The study uses an acceptance perspective and a product-quality model, supported by an architectural description and access-control principles. These serve distinct purposes and are not all presented as competing theories of user behavior.',
389: '1. Technology Acceptance Model (Davis, 1989)',
390: 'Davis (1989) develops measures of perceived usefulness and perceived ease of use relevant to technology acceptance. In this study, these constructs provide a basis for asking whether the system helps users carry out their work and whether its operations are understandable. They must be operationalized in the evaluation instrument before any TAM-based conclusion is reported.',
391: 'Perceived Usefulness: Planned questions and task observations will address submission visibility, access to recommendations, and organization of research records. They will not assume that time savings or institutional adoption have already occurred.',
392: 'Perceived Ease of Use: Planned questions and observations will address learning the interface, finding records, understanding permissions, and completing permitted actions. Using a particular frontend library is not treated as evidence that the interface is easy to use.',
395: '2. Architectural and Access-Control Basis',
396: 'The implementation is described through presentation, application processing, and relational persistence. These are logical responsibilities rather than a claim of physically separated deployment tiers:',
397: 'Presentation: Browser-delivered HTML, CSS, and JavaScript with role-specific navigation, research forms, record views, and status indicators.',
398: 'Application Processing: PHP handles authorization, validation, uploads/downloads, locked submissions, version capture, and review decisions. Role-based access principles (Ferraiolo & Kuhn, 1992) are applied through administrator authority, explicit staff permissions, and applicable record-access rules.',
399: 'Persistence: Relational records connect researchers, projects, chapters, preserved versions, decisions, activities, publications, and IP records, with document metadata linked to stored files. Persistence and access behavior must be verified through relevant test cases.',
404: '3. Selected ISO/IEC 25010:2011 Product-Quality Characteristics',
405: 'Functional Suitability, Usability, Performance Efficiency, Reliability, and Security organize the proposed evaluation. The final methodology must justify this selection and the use of the historical 2011 edition, specify the measures, and document actual test and respondent results. The 2023 edition is acknowledged as a revision; terminology and questionnaire items from different editions must not be combined without an explicit mapping.',
416: 'The Input–Process–Output model relates documented requirements and institutional context to development, verification, and planned evaluation. The expected output is an implemented RMS accompanied by evidence about the tasks and conditions actually evaluated. The framework does not presume favorable ratings, complete institutional compliance, or measured improvements before data collection.',
429: 'Figure 2.2. Conceptual Framework (Input–Process–Output Model)',
433: 'Input: Institutional documents and validated campus requirements; needs of administrators, research staff, faculty users, and students; the current title-and-chapter workflow and record-access rules; the implemented technology stack; supporting activity, publication, and IP functions; and the selected evaluation characteristics and baseline task definitions.',
434: 'Process: Validate requirements and the study boundary; align the architecture, data, permissions, and interfaces; develop connected workflows; verify draft editing, locking, sequential chapters, decisions, history, and record persistence; check invalid and unauthorized requests; conduct user acceptance tasks and collect quality assessments; and compare documented baseline and RMS task observations. Report only testing and data collection actually completed.',
435: 'Output: The implemented RMS with shared student/faculty title and chapter processing, authorized review, revision history, chapter-progress indicators, research activities, publication and IP records, archive views, and administrative functions. Evaluation reports will state the tested scope, observed results, unresolved defects, and limitations. No quality rating, percentage improvement, or deployment success is presumed by this framework.',
}

TABLES = [
[
['System / source', 'Documented capabilities', 'Relevance', 'Boundary for comparison'],
['UE Research Management System (Celis & Lagda, 2025)', 'Submission, approval, research profiles, reporting; user evaluation.', 'Philippine research-administration comparator.', 'Abstract-level evidence; its findings do not establish EARIST outcomes.'],
['DSpace (Smith et al., 2003)', 'Institutional repository, metadata, retrieval, and submission workflows.', 'Deposit, review, discovery, and preservation responsibilities.', 'EARIST-specific sequential chapter rules are not established by this source.'],
['EPrints (EPrints Services, n.d.)', 'Open-source repository platform and repository services.', 'Institutional repository alternative.', 'Capabilities depend on configuration; assess locally before claiming a feature is absent.'],
['Google Classroom (Google, n.d.)', 'Educational coordination, classwork, and feedback.', 'General classroom-workflow comparator.', 'Official overview does not establish an EARIST research lifecycle or its permission model.'],
['EARIST RMS (implementation reviewed, 2026)', 'Shared title/chapter submissions; versions and locks; administrator/authorized-staff decisions; connected research records.', 'Institution-specific implementation to evaluate.', 'Institutional equivalence and performance benefits require separate evidence.'],
],
[['Figure 2.1. Bases of the Study\n\nACCEPTANCE: TAM (Davis, 1989)\nPerceived usefulness and perceived ease of use guide planned user assessments.\n\nARCHITECTURE AND AUTHORIZATION\nBrowser presentation → PHP processing → relational persistence.\nRole-based access principles (Ferraiolo & Kuhn, 1992), explicit staff permissions, and record-access rules.\n\nPRODUCT QUALITY\nFive selected ISO/IEC 25010:2011 characteristics organize planned technical and user evaluation.\n\nThese bases guide evaluation; they are not completed results.']],
[['INPUT\n\n• Institutional documents and validated campus requirements\n• Administrator, staff, faculty, and student tasks\n• Shared title/chapter workflow and access rules\n• Activity, publication, IP, and archive requirements\n• HTML, CSS, JavaScript, PHP/MySQLi, relational database, XAMPP\n• Selected quality characteristics and baseline tasks']],
[['PROCESS\n\n1. Validate requirements and evaluation scope\n2. Align architecture, records, permissions, and interfaces\n3. Develop connected persistent behavior\n4. Verify workflow, locks, history, invalid requests, and access\n5. Conduct user tasks and collect assessments\n6. Compare baseline observations and report limitations\n\nMutating browser checks require a verified disposable test database.']],
[['EXPECTED OUTPUT\n\n• Implemented RMS and documented module boundaries\n• Title/chapter versions, authorized decisions, and revision history\n• Computed chapter-progress and connected research records\n• Test evidence and user findings for the evaluated scope\n• Documented defects and limitations\n\nResults are reported after testing and evaluation; no favorable rating is assumed.']],
]

# References are restricted to material actually used in the revised manuscript.
REFERENCES = [
'Adriano, R., Concepcion, A. U., Cruz, M. M. S., & Concepcion, A. T. V. (2025). A segmentation analysis utilizing natural language processing model with interactive data analytics dashboard for research management platform. International Journal of Multidisciplinary: Applied Business and Education Research, 6(2), 892–904. https://doi.org/10.11594/ijmaber.06.02.31',
'Brooks, D. C., & McCormack, M. (2020). Driving digital transformation in higher education. EDUCAUSE. https://www.educause.edu/ecar/research-publications/driving-digital-transformation-in-higher-education/2020/defining-digital-transformation',
'Celis, J. R., & Lagda, E. (2025). Development and evaluation of UE – Research Management System [Abstract]. UE Research Journal, 28(2). https://ejournals.ph/article.php?id=34074',
'Davis, F. D. (1989). Perceived usefulness, perceived ease of use, and user acceptance of information technology. MIS Quarterly, 13(3), 319–340. https://doi.org/10.2307/249008',
'EARIST. (2015). EARIST research manual [Institutional reference; repository transcription: docs/reference/Research-Manual_2015.md]. Eulogio “Amang” Rodriguez Institute of Science and Technology.',
'Elmasri, R., & Navathe, S. B. (2016). Fundamentals of database systems (7th ed., global ed.). Pearson. https://www.pearson.com/en-gb/subject-catalog/p/Elmasri-Fundamentals-of-Database-Systems-Global-Edition-7th-Edition/P200000004142',
'EPrints Services. (n.d.). EPrints. Retrieved October 20, 2026, from https://www.eprints.org/',
'Ferraiolo, D. F., & Kuhn, D. R. (1992). Role-based access controls. Proceedings of the 15th National Computer Security Conference (pp. 554–563). https://www.nist.gov/publications/role-based-access-controls',
'Google. (n.d.). About Classroom. Retrieved October 20, 2026, from https://support.google.com/edu/classroom/answer/6020279?hl=en',
'ISO/IEC. (2011). Systems and software engineering—Systems and software Quality Requirements and Evaluation (SQuaRE)—System and software quality models (ISO/IEC 25010:2011). International Organization for Standardization. https://www.iso.org/standard/35733.html',
'ISO/IEC. (2023). Systems and software engineering—Systems and software Quality Requirements and Evaluation (SQuaRE)—Product quality model (ISO/IEC 25010:2023). International Organization for Standardization. https://www.iso.org/standard/78176.html',
'Laudon, K. C., & Laudon, J. P. (2020). Management information systems: Managing the digital firm (16th ed., global ed.). Pearson. https://www.pearson.com/se/Nordics-Higher-Education/subject-catalogue/information-systems/Laudon-management-information-systems-digital-firm-16e.html/1000',
'Mkumbwa, R. D., Pancras, G., & Sirili, N. S. (2025). Going paperless: The strengths and limitations of electronic research ethics information management system in a health training institution in Tanzania. BMC Health Services Research, 25, Article 12. https://doi.org/10.1186/s12913-024-12151-0',
'The PHP Group. (n.d.). Prepared statements. PHP manual. Retrieved October 20, 2026, from https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php',
'Smith, M., Barton, M., Bass, M., Branschofsky, M., McClellan, G., Stuve, D., Tansley, R., & Walker, J. H. (2003). DSpace: An open source dynamic digital repository. D-Lib Magazine, 9(1). https://doi.org/10.1045/january2003-smith',
'Venkatesh, V., Thong, J. Y. L., & Xu, X. (2016). Unified theory of acceptance and use of technology: A synthesis and the road ahead. Journal of the Association for Information Systems, 17(5). https://doi.org/10.17705/1jais.00428',
]


def children(node, name):
    return [x for x in node.childNodes if x.nodeType == x.ELEMENT_NODE and x.namespaceURI == W and x.localName == name]


def text_of(node):
    parts = []
    def visit(item):
        if item.nodeType == item.DOCUMENT_NODE:
            for child in item.childNodes:
                visit(child)
            return
        if item.nodeType != item.ELEMENT_NODE:
            return
        if item.namespaceURI == W and item.localName == 't':
            parts.append(item.firstChild.data if item.firstChild else '')
        elif item.namespaceURI == W and item.localName in ['br', 'cr']:
            parts.append('\n')
        elif item.namespaceURI == W and item.localName == 'tab':
            parts.append('\t')
        else:
            for child in item.childNodes:
                visit(child)
    visit(node)
    return ''.join(parts)


def replace_paragraph(doc, p, text, *, size=None, bold=None):
    props = children(p, 'pPr')
    base = children(props[0], 'rPr') if props else []
    rprops = base[0].cloneNode(True) if base else doc.createElementNS(W, 'w:rPr')
    for x in list(p.childNodes):
        if x.nodeType == x.ELEMENT_NODE and x.namespaceURI == W and x.localName in ['pPr', 'bookmarkStart', 'bookmarkEnd']:
            continue
        p.removeChild(x)
    # Use the original paragraph's font and spacing; avoid carrying incidental
    # hyperlink, italic, or run-specific bold from the replaced sentence.
    for name in ['rStyle', 'i', 'iCs', 'sz', 'szCs']:
        for x in children(rprops, name):
            rprops.removeChild(x)
    if bold is not None:
        for name in ['b', 'bCs']:
            for x in children(rprops, name): rprops.removeChild(x)
            if bold: rprops.appendChild(doc.createElementNS(W, 'w:' + name))
    for name in ['sz', 'szCs']:
        x = doc.createElementNS(W, 'w:' + name)
        x.setAttributeNS(W, 'w:val', str(size or 24))
        rprops.appendChild(x)
    run = doc.createElementNS(W, 'w:r')
    run.appendChild(rprops)
    for i, line in enumerate(text.split('\n')):
        if i: run.appendChild(doc.createElementNS(W, 'w:br'))
        t = doc.createElementNS(W, 'w:t')
        t.setAttribute('xml:space', 'preserve')
        t.appendChild(doc.createTextNode(line))
        run.appendChild(t)
    p.appendChild(run)


def prop(doc, parent, name, **attrs):
    found = children(parent, name)
    x = found[0] if found else doc.createElementNS(W, 'w:' + name)
    if not found:
        if name in ['pPr', 'trPr'] and parent.firstChild:
            parent.insertBefore(x, parent.firstChild)
        else:
            parent.appendChild(x)
    for key, value in attrs.items(): x.setAttributeNS(W, 'w:' + key, str(value))
    return x


def build():
    source_hash = hashlib.sha256(SOURCE.read_bytes()).hexdigest()
    if source_hash != EXPECTED_SOURCE_HASH:
        raise RuntimeError('Source DOCX changed; recheck paragraph mapping before rebuilding.')
    with zipfile.ZipFile(SOURCE) as package:
        doc = minidom.parseString(package.read('word/document.xml'))
        body = doc.getElementsByTagNameNS(W, 'body')[0]
        paragraphs = children(body, 'p')
        tables = children(body, 'tbl')
        assert len(paragraphs) >= 502 and len(tables) == 5
        original = {i: text_of(p) for i, p in enumerate(paragraphs)}
        bold_indexes = {334,335,337,341,343,345,348,349,351,356,358,360,389,395,404}
        changes = []
        for i, text in sorted(EDITS.items()):
            replace_paragraph(doc, paragraphs[i], text, bold=(i in bold_indexes))
            changes.append({'original_paragraph_index':i, 'before':original[i], 'after':text})
        for ti, (table, rows) in enumerate(zip(tables, TABLES)):
            actual_rows = children(table, 'tr')
            if ti > 0:
                title, content = rows[0][0].split('\n\n', 1)
                rows = [[title], [content]]
            assert len(rows) == len(actual_rows), (ti, len(rows), len(actual_rows))
            for ri, (row, values) in enumerate(zip(actual_rows, rows)):
                row_props = prop(doc, row, 'trPr')
                for height in children(row_props, 'trHeight'):
                    row_props.removeChild(height)
                if ti == 0:
                    prop(doc, row_props, 'cantSplit')
                cells = children(row, 'tc')
                assert len(cells) == len(values)
                for cell, value in zip(cells, values):
                    ps = children(cell, 'p')
                    p = ps[0]
                    for extra in ps[1:]: cell.removeChild(extra)
                    replace_paragraph(doc, p, value, size=20 if ti == 0 else 22, bold=(ri == 0))
                    pp = prop(doc, p, 'pPr')
                    prop(doc, pp, 'spacing', before=0, after=100, line=260, lineRule='auto')
                    prop(doc, pp, 'ind', left=0, right=0, firstLine=0, hanging=0)
                    prop(doc, pp, 'jc', val='left')
                if ti == 0 and ri == 0:
                    prop(doc, prop(doc, row, 'trPr'), 'tblHeader')
        # Replace the old bibliography as a unit; no stale hyperlink fields survive.
        reference_template = paragraphs[449].cloneNode(True)
        ref_position = body.childNodes.index(paragraphs[448]) + 1
        for p in paragraphs[449:]: body.removeChild(p)
        for citation in REFERENCES:
            p = reference_template.cloneNode(True)
            # Cloned w14 paragraph IDs must remain unique in the document.
            para_id = int(hashlib.sha256(citation.encode('utf-8')).hexdigest()[:8], 16) & 0x7fffffff
            p.setAttributeNS('http://schemas.microsoft.com/office/word/2010/wordml', 'w14:paraId', f'{para_id:08X}')
            for mark in list(p.getElementsByTagNameNS(W, 'bookmarkStart')) + list(p.getElementsByTagNameNS(W, 'bookmarkEnd')):
                mark.parentNode.removeChild(mark)
            replace_paragraph(doc, p, citation, bold=False)
            pp = prop(doc, p, 'pPr')
            prop(doc, pp, 'ind', left=720, hanging=720, firstLine=0)
            prop(doc, pp, 'spacing', before=0, after=160, line=480, lineRule='auto')
            prop(doc, pp, 'keepLines')
            body.insertBefore(p, body.childNodes[ref_position] if ref_position < len(body.childNodes) else None)
            ref_position += 1
        settings = minidom.parseString(package.read('word/settings.xml'))
        prop(settings, settings.documentElement, 'updateFields', val='true')
        with zipfile.ZipFile(DEST, 'w', compression=zipfile.ZIP_DEFLATED) as out:
            for item in package.infolist():
                if item.filename == 'word/document.xml': data = doc.toxml(encoding='UTF-8')
                elif item.filename == 'word/settings.xml': data = settings.toxml(encoding='UTF-8')
                else: data = package.read(item.filename)
                out.writestr(item, data)
    (HERE / 'paragraph-changes.json').write_text(json.dumps(changes, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    # A complete textual companion makes review possible without Word.
    blocks = []
    for node in body.childNodes:
        if node.nodeType != node.ELEMENT_NODE: continue
        if node.localName == 'p':
            text = text_of(node)
            if text.strip(): blocks.append(text)
        elif node.localName == 'tbl':
            rows = []
            for row in children(node, 'tr'):
                rows.append(' | '.join(text_of(c).replace('\n', ' / ') for c in children(row, 'tc')))
            blocks.append('\n'.join(rows))
    (HERE / 'rms-chapters-revised-text.txt').write_text('\n\n'.join(blocks) + '\n', encoding='utf-8')
    validate(source_hash)


def validate(source_hash):
    with zipfile.ZipFile(DEST) as z:
        assert z.testzip() is None
        for name in z.namelist():
            if name.endswith('.xml') or name.endswith('.rels'): minidom.parseString(z.read(name))
        d = minidom.parseString(z.read('word/document.xml'))
        text = text_of(d)
        for bad in ['Bootstrap', 'DataTables', 'Chart.js', 'Dompdf']:
            # Each appears once, only in the explicit implementation exclusion.
            assert text.count(bad) == 1, bad
        for bad in ['over 85%', 'less than one week', 'Al-Hajj', 'Pascua et al.', 'ISO, 2017', 'final de']:
            assert bad not in text, bad
        assert len(d.getElementsByTagNameNS(W, 'tbl')) == 5
        ref_nodes = children(d.getElementsByTagNameNS(W, 'body')[0], 'p')[-len(REFERENCES):]
        assert len({p.getAttribute('w14:paraId') for p in ref_nodes}) == len(REFERENCES)
        for row in d.getElementsByTagNameNS(W, 'tr'):
            elements = [x.localName for x in row.childNodes if x.nodeType == x.ELEMENT_NODE]
            if 'trPr' in elements:
                assert elements.index('trPr') < elements.index('tc')
        assert 'Figure 2.1.' in text and 'Figure 2.2.' in text
        assert all(EDITS[i] in text for i in EDITS)
        with zipfile.ZipFile(SOURCE) as src:
            preserved = [x for x in src.namelist() if x not in ['word/document.xml','word/settings.xml']]
            assert all(src.read(x) == z.read(x) for x in preserved)
    assert hashlib.sha256(SOURCE.read_bytes()).hexdigest() == source_hash
    results = {
        'source_sha256': source_hash,
        'revised_sha256': hashlib.sha256(DEST.read_bytes()).hexdigest(),
        'source_preserved': True,
        'zip_crc_check': 'passed',
        'all_xml_parts_parse': 'passed',
        'unchanged_package_parts': len(preserved),
        'paragraph_replacements': len(EDITS),
        'references_in_revised_copy': len(REFERENCES),
        'tables_revised': 5,
        'word_layout_review': 'See word-layout-review.json; package checks do not verify rendered layout',
        'application_runtime_tests': 'not run; documentation-only work',
        'database_changes': 'none',
    }
    (HERE / 'revision-validation.json').write_text(json.dumps(results, indent=2)+'\n', encoding='utf-8')
    print(json.dumps(results, indent=2))


if __name__ == '__main__':
    build()
