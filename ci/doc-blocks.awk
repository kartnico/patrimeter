# Extracts from a Markdown page the ```sh blocks preceded by the line given by -v marker=… (for instance
# <!-- ci:install -->): this is how the CI runs the installation documentation.
$0 == marker { armed = 1; next }
armed && /^```sh$/ { inside = 1; armed = 0; next }
inside && /^```$/ { inside = 0; print ""; next }
inside { print }
