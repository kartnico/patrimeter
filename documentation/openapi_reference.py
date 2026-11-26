"""Page « Référence de l'API » de la documentation, générée depuis la description OpenAPI de sa langue
(docs/openapi.yaml en français, docs/openapi.en.yaml en anglais).

Appelé par prepare.sh à chaque construction : la description reste la seule source, rien n'est recopié à la main.
PyYAML est fourni avec Zensical. Usage : python3 documentation/openapi_reference.py <fr|en> <openapi.yaml> <page.md>
"""

import json
import sys

import yaml

METHODS = ("get", "post", "put", "patch", "delete")

# Fixed texts of the page, per language (the others come from the OpenAPI description).
TEXTS = {
    "fr": {
        "title": "Référence de l'API",
        "generated": "<!-- Page générée depuis docs/openapi.yaml par documentation/openapi_reference.py : ne pas la modifier. -->",
        "base": "Adresse de base : `{url}` (remplacez l'origine par celle de votre installation).",
        "guide": "Guide d'utilisation, OAuth et service MCP : [API REST et service MCP](api.md).",
        "download": "Description OpenAPI {version} complète, à importer dans un client (Bruno, Insomnia, Postman…) ou un "
        "générateur de code : [openapi.yaml](openapi.yaml).",
        "authentication": "Authentification",
        "scopes": "Portées",
        "scope": "Portée",
        "colon": " : ",
        "schemas": "Schémas",
        "type": "Type",
        "pattern": "Motif",
        "or": " ou ",
        "list_of": "liste de {item}",
        "null": "nul",
        "object": "objet",
        "range": "{min} à {max}",
        "all_fields_of": "*tous les champs de*",
        "field_header": "| Champ | Type | Obligatoire | Description |",
        "yes": "oui",
        "no": "non",
        "parameter_header": "| Paramètre | Où | Type | Description |",
        "where": {"path": "chemin", "query": "requête"},
        "body": "Corps (JSON)",
        "response_header": "| Statut | Réponse |",
        "object_with": "Objet : ",
    },
    "en": {
        "title": "API reference",
        "generated": "<!-- Page generated from docs/openapi.en.yaml by documentation/openapi_reference.py: do not edit it. -->",
        "base": "Base address: `{url}` (replace the origin with that of your installation).",
        "guide": "User guide, OAuth and MCP service: [REST API and MCP service](api.md).",
        "download": "Complete OpenAPI {version} description, to import into a client (Bruno, Insomnia, Postman…) or a "
        "code generator: [openapi.yaml](openapi.yaml).",
        "authentication": "Authentication",
        "scopes": "Scopes",
        "scope": "Scope",
        "colon": ": ",
        "schemas": "Schemas",
        "type": "Type",
        "pattern": "Pattern",
        "or": " or ",
        "list_of": "list of {item}",
        "null": "null",
        "object": "object",
        "range": "{min} to {max}",
        "all_fields_of": "*all the fields of*",
        "field_header": "| Field | Type | Required | Description |",
        "yes": "yes",
        "no": "no",
        "parameter_header": "| Parameter | In | Type | Description |",
        "where": {"path": "path", "query": "query"},
        "body": "Body (JSON)",
        "response_header": "| Status | Response |",
        "object_with": "Object: ",
    },
}
T = TEXTS["fr"]


def ref_name(ref):
    return ref.rsplit("/", 1)[-1]


def anchor(name):
    return name.lower()


def type_of(schema):
    """Type lisible d'un schéma, avec un lien vers les schémas nommés."""
    if "$ref" in schema:
        name = ref_name(schema["$ref"])
        return f"[{name}](#{anchor(name)})"
    if "oneOf" in schema:
        return T["or"].join(type_of(part) for part in schema["oneOf"])
    if "allOf" in schema:
        return " + ".join(type_of(part) for part in schema["allOf"])
    kind = schema.get("type", T["object"])
    if isinstance(kind, list):
        return T["or"].join(T["null"] if k == "null" else k for k in kind)
    if kind == "array":
        return T["list_of"].format(item=type_of(schema.get("items", {})))
    if kind == "null":
        return T["null"]
    details = []
    if "enum" in schema:
        details.append(", ".join(f"`{v}`" for v in schema["enum"]))
    if "format" in schema:
        details.append(schema["format"])
    if "minimum" in schema or "maximum" in schema:
        details.append(T["range"].format(min=schema.get("minimum", "…"), max=schema.get("maximum", "…")))
    return kind + (f" ({' ; '.join(details)})" if details else "")


def cell(text):
    return " ".join(str(text).split()).replace("|", "\\|")


def fields_table(schema, spec):
    """Tableau des champs d'un objet ; None si le schéma n'en a pas."""
    parts = schema.get("allOf", [schema])
    rows = []
    for part in parts:
        if "$ref" in part:
            rows.append(f"| {T['all_fields_of']} {type_of(part)} | | | |")
            continue
        required = set(part.get("required", []))
        for name, field in part.get("properties", {}).items():
            rows.append(
                f"| `{name}` | {cell(type_of(field))} | {T['yes'] if name in required else T['no']} | {cell(field.get('description', ''))} |"
            )
    if not rows:
        return None
    return T["field_header"] + "\n|---|---|---|---|\n" + "\n".join(rows)


def resolve(node, spec):
    while "$ref" in node:
        target = spec
        for part in node["$ref"].split("/")[1:]:
            target = target[part]
        node = target
    return node


def operation_section(method, path, operation, path_parameters, spec):
    lines = [f"### `{method.upper()} {path}`", "", f"**{operation.get('summary', '')}**", ""]
    if operation.get("description"):
        lines += [operation["description"].strip(), ""]
    scopes = [
        scope
        for requirement in operation.get("security", spec.get("security", []))
        for scope in requirement.get("oauth", [])
    ]
    if scopes:
        lines += [f"{T['scope']}{T['colon']}`{' '.join(scopes)}`.", ""]

    parameters = [resolve(p, spec) for p in path_parameters + operation.get("parameters", [])]
    if parameters:
        lines += [T["parameter_header"], "|---|---|---|---|"]
        for p in parameters:
            where = T["where"].get(p["in"], p["in"])
            lines.append(f"| `{p['name']}` | {where} | {cell(type_of(p.get('schema', {})))} | {cell(p.get('description', ''))} |")
        lines.append("")

    body = operation.get("requestBody", {}).get("content", {}).get("application/json")
    if body:
        lines += [f"{T['body']}{T['colon']}{type_of(body['schema'])}.", ""]
        if "example" in body:
            lines += ["```json", json.dumps(body["example"], ensure_ascii=False), "```", ""]

    lines += [T["response_header"], "|---|---|"]
    for status, response in operation.get("responses", {}).items():
        response = resolve(response, spec)
        content = response.get("content", {})
        schema = next(iter(content.values()), {}).get("schema") if content else None
        detail = cell(response.get("description", ""))
        if schema is not None:
            detail += f" {type_of(schema) if '$ref' in schema else T['object_with'] + ', '.join(f'`{k}` ({type_of(v)})' for k, v in schema.get('properties', {}).items())}"
        lines.append(f"| `{status}` | {cell(detail)} |")
    lines.append("")
    return lines


def main(lang, source, target):
    global T
    T = TEXTS[lang]
    with open(source, encoding="utf-8") as handle:
        spec = yaml.safe_load(handle)

    info = spec["info"]
    server = spec["servers"][0]
    origin = server["variables"]["origin"]["default"]
    out = [
        f"# {T['title']}",
        "",
        T["generated"],
        "",
        info["description"].strip(),
        "",
        T["base"].format(url=server["url"].replace("{origin}", origin)),
        T["guide"],
        "",
        T["download"].format(version=spec["openapi"]),
        "",
        f"## {T['authentication']}",
        "",
    ]
    separator = " ; " if lang == "fr" else "; "
    for name, scheme in spec["components"]["securitySchemes"].items():
        out += [f"- **{name}**{T['colon']}{cell(scheme.get('description', ''))}"]
        for flow in scheme.get("flows", {}).values():
            out += [f"  {T['scopes']}{T['colon']}" + separator.join(f"`{s}` ({d.rstrip('.')})" for s, d in flow["scopes"].items()) + "."]
    out.append("")

    for tag in spec.get("tags", []):
        operations = [
            (method, path, item[method], item.get("parameters", []))
            for path, item in spec["paths"].items()
            for method in METHODS
            if method in item and tag["name"] in item[method].get("tags", [])
        ]
        if not operations:
            continue
        out += [f"## {tag['name']}", ""]
        if tag.get("description"):
            out += [tag["description"], ""]
        for method, path, operation, path_parameters in operations:
            out += operation_section(method, path, operation, path_parameters, spec)

    out += [f"## {T['schemas']}", ""]
    for name, schema in spec["components"]["schemas"].items():
        out += [f"### {name}", ""]
        if schema.get("description"):
            out += [cell(schema["description"]), ""]
        table = fields_table(schema, spec)
        out += [table if table else f"{T['type']}{T['colon']}{cell(type_of(schema))}.", ""]
        if "pattern" in schema:
            out += [f"{T['pattern']}{T['colon']}`{schema['pattern']}`.", ""]

    with open(target, "w", encoding="utf-8") as handle:
        handle.write("\n".join(out))


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2], sys.argv[3])
