# Candidate Pictures Directory

This directory stores profile pictures for survey candidates.

## Directory Structure

```
public/assets/images/candidates/
├── README.md          # This file
├── placeholder.png    # Default placeholder image for candidates without pictures
└── [candidate-id].png # Individual candidate pictures (to be added)
```

## Usage

### Placeholder Image
- **File**: `placeholder.png`
- **Purpose**: Default image displayed when a candidate doesn't have a custom picture
- **Format**: PNG with transparent or solid background
- **Dimensions**: Circular/square format suitable for profile display

### Candidate Pictures
- **Naming Convention**: Use candidate ID or unique identifier (e.g., `candidate_one.png`, `candidate_two.png`)
- **Recommended Format**: PNG or JPG
- **Recommended Dimensions**: 200x200px minimum (square aspect ratio)
- **File Size**: Keep under 500KB for optimal loading performance

## Adding New Candidate Pictures

### Method 1: Automatic (using candidate ID)
1. Save the candidate picture with a filename matching the candidate ID
2. Place the file in this directory
3. The system will automatically use the image if it matches the candidate ID

### Method 2: Custom Image Name (via JSON configuration)
1. Save the candidate picture in this directory with any filename (e.g., `photo1.png`)
2. Open `php-survey-backend/vote_data/candidates.json`
3. Add an `"image"` field to the candidate object:
   ```json
   {
     "id": "candidate_one",
     "name": "Candidate One",
     "linkedin": "https://www.linkedin.com/in/candidate-one",
     "image": "candidate_one.png",
     "statement": "..."
   }
   ```
4. The image field should contain ONLY the filename (not the full path)
5. If no `"image"` field is specified, the system falls back to `placeholder.png`

## URL Path

Access candidate pictures via:
```
/assets/images/candidates/[filename].png
```

Example:
```
/assets/images/candidates/placeholder.png
```
