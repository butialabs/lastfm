"use strict";

const gulp = require('gulp');
const sass = require('gulp-sass')(require('sass'));
const concat = require('gulp-concat');
const clean_css = require('gulp-clean-css');
const sourcemaps = require('gulp-sourcemaps');
const imagemin = require('gulp-imagemin');
const webp = require('gulp-webp');
const rev = require('gulp-rev');
const rev_del = require('rev-del');
const newer = require('gulp-newer');

const paths = {
    scripts: {
		src: 'assets/js/*.js',
		dest: 'public/dist/js'
	},
	styles: {
		src: 'assets/scss/*.scss',
		dest: 'public/dist/css'
	},
	images: {
		src: 'assets/images/**/*',
		dest: 'public/dist/images'
	},
};

function styles() {
	return gulp.src(paths.styles.src)
		.pipe(sourcemaps.init())
		.pipe(sass({
			outputStyle: "expanded",
			includePaths: ['./node_modules']
		}))
		.pipe(concat('style.css'))
		.pipe(clean_css())
		.pipe(rev())
		.pipe(gulp.dest(paths.styles.dest))
		.pipe(rev.manifest())
		.pipe(rev_del({ dest: paths.styles.dest }))
		.pipe(sourcemaps.write('.'))
		.pipe(gulp.dest(paths.styles.dest))
}

function scripts() {
	return gulp.src(paths.scripts.src)
		.pipe(sourcemaps.init())
		.pipe(concat('scripts.js'))
		.pipe(rev())
		.pipe(gulp.dest(paths.scripts.dest))
		.pipe(rev.manifest())
		.pipe(rev_del({ dest: paths.scripts.dest }))
		.pipe(sourcemaps.write('.'))
		.pipe(gulp.dest(paths.scripts.dest))
}

function images() {
	return gulp.src(paths.images.src)
		.pipe(newer(paths.images.dest))
		.pipe(imagemin())
		.pipe(gulp.dest(paths.images.dest))
		.pipe(webp())
		.pipe(gulp.dest(paths.images.dest))
}

function watch() {
	gulp.watch(paths.styles.src, styles);
    gulp.watch(paths.scripts.src, scripts);
	gulp.watch(paths.images.src, images);
}

exports.default = gulp.series(
	gulp.parallel(styles, scripts, images),
	watch
);