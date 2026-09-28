# Offline fixture adapter only, NOT an application/installer dependency.
# Used when local PHP lacks php-yaml: parse the shipped, unchanged templates with Psych.
require 'yaml'
require 'json'
directory = File.expand_path('../fragebogenpi/_yaml', __dir__)
puts Dir[File.join(directory, '*.yaml')].to_h { |file| [File.basename(file), YAML.safe_load(File.read(file))] }.to_json
