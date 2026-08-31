# wgims-documentation

## WGIMS Documentation Rules

This skill contains the rules for creating accurate system documentation and user manuals for WGIMS.

## Documentation Types

### System Documentation
- Architecture overview
- Database schema diagrams
- Module relationship maps
- Business rules reference
- Deployment guide

### User Manuals
- Step-by-step workflows with screenshots
- Role-based instructions (Admin, Warehouse Manager, Custodian, Staff)
- Glossary of terms

## Documentation Rules

### Accuracy
- Only document what actually exists in the code
- Do not invent features or workflows
- If unsure, verify against the codebase first
- Update documentation when code changes

### WGIMS Terminology
Use the correct terminology:
- **Subsidy** — not "Purchase Order" (though PO table exists historically)
- **Delivery** — a delivery event within a subsidy
- **RIS** — Requisition and Issue Slip
- **DR Number** — Delivery Receipt number
- **ENGAS** — cost per item unit
- **Stock Card** — transaction history for a stock record
- **RPCI** — Report on Physical Count of Inventories
- **RSMI** — Report of Supplies and Materials Issued
- **Stock Transfer** — movement between warehouses

### Workflow Documentation
For each workflow, document:
1. **Purpose** — why this workflow exists
2. **Prerequisites** — what must exist first
3. **Steps** — numbered sequence of actions
4. **Validation** — what checks are performed
5. **Outcomes** — what is created/modified
6. **Reversal** — how to undo if needed

### Code Documentation
When documenting code:
- Reference actual file paths and line numbers
- Include relevant model relationships
- Include relevant database table structures
- Note any business rules encoded in the code

### Screenshots
- Use actual application screenshots
- Annotate with arrows and labels
- Include browser/zoom level in filename if relevant

## Constraints
- **DO NOT** document features that don't exist
- **DO NOT** change code while creating documentation
- **DO NOT** guess at business rules — verify in code
