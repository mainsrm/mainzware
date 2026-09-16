# MainzWare Budget — Agent Plans

This directory contains two viable implementation pathways for the MainzWare Budget application.

## Pathway 1
`PATHWAY-1-APPLICATION-HOSTED-AI.md`

The application hosts the AI inference workload alongside the PHP API, PostgreSQL, Redis and agent worker during the initial deployment. The architecture permits later separation and scaling.

## Pathway 2
`PATHWAY-2-DEDICATED-RECEIPT-AI-API.md`

The budget application calls a separately hosted MainzWare Receipt AI API. The AI service owns specialized model inference, retrieval, model lifecycle and training/evaluation infrastructure.

## Shared Agent Instructions
`AGENT-INSTRUCTIONS.md`

These instructions apply to VS Code coding agents working on either pathway.

## How to use

Start an implementation session by giving the coding agent the relevant pathway document and `AGENT-INSTRUCTIONS.md`.

For Pathway 1:
- Read `AGENT-INSTRUCTIONS.md`
- Read `PATHWAY-1-APPLICATION-HOSTED-AI.md`
- Inspect the repository
- Implement only the requested phase/task

For Pathway 2:
- Read `AGENT-INSTRUCTIONS.md`
- Read `PATHWAY-2-DEDICATED-RECEIPT-AI-API.md`
- Inspect the repository
- Preserve the Budget App / Receipt AI API boundary
- Implement only the requested phase/task

These documents are architectural plans and implementation guardrails. The agent must inspect the actual repository before assuming a particular framework, file layout or existing component.
