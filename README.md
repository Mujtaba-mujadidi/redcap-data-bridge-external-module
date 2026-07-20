# OVG AutoQuery Assistant - V1.0 (01/10/2025)

## Overview

This is a REDCap External Module that enhances data quality management by validating form entries in real-time and automatically raising data queries for any discrepancies found once the form is saved a complete status. It helps streamline the query process and reduces manual workload for data managers and monitors.

## Key Features

- Automatically validates data **on form save**
- Detects:
  - Missing required fields
  - Invalid or unexpected values
  - Out-of-range entries
  - Unexpected values
- Filters out fields with existing queries
- Prompts the user with a **modal review window** listing all issues
- Allows the user to:
  - Return to the form and fix issues
  - Proceed and automatically raise queries assigned to themselves
- Raises queries as the **system administrator**
- Logs all actions in REDCap’s logging system for transparency

