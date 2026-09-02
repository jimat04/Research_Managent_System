# EARIST Research Manual 2015 — RMS Reference

This document summarizes the research governance, procedures, and prescribed forms in the EARIST Research Manual 2015. The source transcription is currently at `reference/Research-Manual_2015.md` and should be moved to the project's intended documentation/reference folder with `git mv` in a separate change.

## Institutional Actors

- **Researcher/Proponent/Grantee:** Prepares the proposal, carries out an approved project, and submits the required progress and terminal reports. Researchers are technically under the Director of the Office of Research Services and administratively under their College Dean or Service Director.
- **College Research Coordinator:** Assists the Dean in supervising and monitoring college research, coordinates college activities with the Office of Research Services, reviews faculty proposals, monitors implementation, and submits periodic and terminal reports to ORS.
- **College Dean:** Receives proposals through the College Research Coordinator, convenes the College Research Evaluation Committee, and serves as CREC chair. The Dean also provides the administrative line between the researcher and the research administration.
- **College Research Evaluation Committee (CREC):** Reviews the college research agenda and capabilities, evaluates college research proposals, conducts research hearings, and endorses proposals to the Director of ORS.
- **Director, Office of Research Services (ORS):** Assists VPREIS in supervising the Institute's research program, coordinates with College Research Coordinators, monitors approved undertakings, maintains research information, and consolidates proposals for VPREIS.
- **Vice President for Research, Extension and Information Services (VPREIS):** Supervises and coordinates research units and programs, recommends research projects, makes sector budget recommendations, and convenes EREC for the Research Forum. Step 8 of the procedures table prints the abbreviation “VPPRE”; this summary treats it as the VPREIS office named throughout the manual.
- **EARIST Research Evaluation Committee (EREC):** Reviews, evaluates, and endorses proposals for the President's approval; monitors approved projects through research reports; approves final project reports; and endorses research incentives.
- **Budget Office:** Clears the availability of funds for a research project before the proponent is informed and the undertaking is signed.
- **EARIST President:** Receives EREC's recommendation and, for Institute-funded research, signs the Memorandum of Research Undertaking as grantor.

CREC means **College Research Evaluation Committee**. EREC means **EARIST Research Evaluation Committee**.

## Research Procedures

The manual's Research Procedures table specifies the following 14-step process:

1. The proponent submits a capsule research proposal (Appendix B/C) to the College Dean through the College Research Coordinator. Innovation or invention proposals must first undergo a patent search at the EARIST Innovation Technology Support Office (ITSO).
2. The College Dean convenes CREC to evaluate the proposal using Appendix D.
3. CREC endorses the proposal to the Director, ORS.
4. The Director, ORS consolidates all proposals and submits them to VPREIS.
5. VPREIS convenes EREC to evaluate the proposals in a Research Forum using Appendix D.
6. EREC recommends approval, revision, or disapproval to the President.
7. The Budget Office clears the availability of project funds.
8. VPREIS informs the proponent through the Dean. The source table uses “VPPRE” in this step.
9. The grantee and President sign the Memorandum of Research Undertaking (Appendix H).
10. The grantee undertakes the research project.
11. The grantee submits a Research Progress Report (Appendix E) midway through the project.
12. The grantee submits a draft Research Terminal Report (Appendix F/G) to EREC for review.
13. The grantee revises the research report if required.
14. The grantee submits bound and CD copies of the Research Terminal Report.

The manual states that proposals are generally expected to be completed within one year and that progress reports are due midway through each project.

## OVPREIS Forms

| Form | Manual title |
|---:|---|
| 1 | Research Proposal (Science/Technology) |
| 2 | Research Proposal (Social/Behavioral) |
| 3 | Research Proposal Evaluation Criteria |
| 4 | Research Progress Report |
| 5 | Research Terminal Report (Science/Technology) |
| 6 | Research Terminal Report (Social/Behavioral) |
| 7 | Memorandum of Research Undertaking |
| 8 | Criteria for Outstanding Research Award |
| 9 | Research Non-Disclosure Form |
| 10 | Application for Research Publication Incentive |
| 11 | Criteria for Outstanding Researcher Award |
| 12 | Research Guidelines for External Researchers |

### OVPREIS Form No. 3 — Research Proposal Evaluation Criteria

| Evaluation criteria | Weight (%) |
|---|---:|
| 1. Contribution to the body of knowledge/ practice | 20 |
| 2. Soundness of research proposal/design | 20 |
| 3. Applicability/Marketability of the research output | 30 |
| 4. Capability of proponent to carry out research project | 10 |
| 5. Aligned with the EARIST and College Research Agenda | 10 |
| 6. Conformity to national research thrusts (DOST/CHED) | 10 |
| **Total** | **100** |

Form No. 3 also provides fields for the proposal title, proponents, ratings, suggestions/recommendations, date, and evaluator signature over printed name.

## RMS Implementation Mapping

The RMS adapts the manual's faculty-research grant process to its available application roles:

| Manual actor or gate | RMS implementation |
|---|---|
| Researcher/Proponent/Grantee | `student` role |
| CREC and EREC members | `faculty` users with the `is_reviewer` flag, assigned through `project_reviews` |
| Director and Office of Research Services | `research_staff` role |
| President's approval | `admin` final-approval gate |
| College Dean, VPREIS, and Budget Office | Consolidated into staff endorsement and admin gates as a deliberate workflow simplification |

Adviser assignment, five-chapter review, and defense scheduling are **academic extensions** for student thesis supervision. They are RMS features, but they are not part of the manual's faculty-grant Research Procedures table and must not be presented as manual-prescribed steps.

## Source Locations

- Institutional actor duties and committee functions: Chapter 4, “Management of Research.”
- Fourteen-step workflow: Chapter 5, “Research Procedures.”
- Form names: Appendix contents and OVPREIS Forms No. 1–12.
- Six evaluation criteria and weights: OVPREIS Form No. 3, “Research Proposal Evaluation Criteria.”
