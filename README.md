# mrp-plugin-v2


``` sh
└── myrealpage
    └── mrp-plugin-v2

```


## Getting Started

Setting up workspace

``` sh
# Clone development and production repositories
cd WORKSPACE_FOLDER
git clone git@github.com:myrealpage/mrp-plugin-v2.git # Development
git clone git@github.com:myrealpagedev/myRealPage-wordpress-plugin.git # Production

# Add git remote development repository to production
cd myRealPage-wordpress-plugin
git remote add dev git@github.com:myrealpage/mrp-plugin-v2.git
git fetch dev
```

Start development.

``` sh
cd WORKSPACE_FOLDER/mrp-plugin-v2
git branch [BRANCH_NAME] # Example: feature-foo, bugfix-bar
git checkout [BRANCH_NAME]
# Work on project
git add [FILES...]
git commit -m "COMMIT MESSAGE"
```

Pushing changes to production

``` sh
cd WORKSPACE_FOLDER/myRealPage-wordpress-plugin
git pull dev master # Pullin from development repository
# Resolve conflicts and commit 
git status
git commit -am "Resolved conflicts"
# Update details.json file by modifying the version
vim details.json # Look for "version": "0.9.48" <-bump
git add details.json
git commit -m "Updating version"
git push # pushing to github
```

When work is complete, merge branch back to master.

```sh
git checkout master
git merge --no-ff [BRANCH_NAME]
```

## Building

``` sh
make build
```

## Testing

http://wp-dev.myrealpage.com/

## Deploying

1. Go to github webpage and go under https://github.com/myrealpagedev/myRealPage-wordpress-plugin/releases
1. Draft new release with the right version (support checks it)


## Testing

http://wp-dev.myrealpage.com/testing-instructions/


## Repositories

| Repository        | Location           |
| ------------- |-------------|
| Development      | https://github.com/myrealpage/mrp-plugin-v2 |
| Releases      | https://github.com/myrealpagedev/myRealPage-wordpress-plugin      |


## Research:
 - https://github.com/ahmadawais/create-guten-block
 